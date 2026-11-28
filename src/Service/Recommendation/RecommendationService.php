<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Recommendation;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Recommendation\RecommendationAlgorithm;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateRefresher;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateSource;
use AlohaSocial\Core\Domain\Recommendation\RecommendationEligibility;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedback;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedbackKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationItem;
use AlohaSocial\Core\Domain\Recommendation\RecommendationPage;
use AlohaSocial\Core\Domain\Recommendation\RecommendationPreference;
use AlohaSocial\Core\Domain\Recommendation\RecommendationRanker;
use AlohaSocial\Core\Domain\Recommendation\RecommendationRepository;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignal;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignalKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSnapshot;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSnapshotCache;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;

use function array_fill_keys;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_values;
use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_strtolower;
use function min;
use function preg_match;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Read and write the optional For You feed.
 *
 * Candidate loading is bounded and offline. Hard filters run before ranking
 * and again when a cached snapshot is read. Signals are opt-in and only ever
 * written for their owner; feedback and reset/export are owner-scoped. A
 * chronological timeline never depends on this service.
 */
final class RecommendationService
{
	public const CONFIG_APP = 'social';

	public const CONFIG_KEY = 'recommendations.enabled';

	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public const MAX_CANDIDATES = 200;

	public const SNAPSHOT_TTL_SECONDS = 300;

	public const MAX_SIGNALS = 5000;

	public const MAX_CURSOR_LENGTH = 2048;

	/**
	 * Stored signals stop influencing ranking after this window. The rows are
	 * kept for export until the owner resets or withdraws consent.
	 */
	public const SIGNAL_RETENTION_SECONDS = 7776000;

	/**
	 * Passive impression/click events collapse into one stored row per
	 * candidate inside this window; explicit interactions are never deduped.
	 */
	public const SIGNAL_NOISE_WINDOW_SECONDS = 86400;

	public function __construct(
		private readonly Config $config,
		private readonly RecommendationCandidateSource $candidates,
		private readonly RecommendationRepository $store,
		private readonly RecommendationEligibility $eligibility,
		private readonly RecommendationRanker $ranker,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly RecommendationSnapshotCache $snapshots,
		private readonly ?RecommendationCandidateRefresher $refresher = null,
	) {
	}

	public function enabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_KEY);
	}

	public function preferences(string $userId): RecommendationPreference
	{
		$now = Time::toSecondPrecision($this->clock->now());

		return $this->store->preference($userId) ?? RecommendationPreference::defaults($userId, $now);
	}

	/**
	 * @param array<string, mixed> $changes
	 *
	 * @throws ValidationException
	 */
	public function updatePreferences(string $userId, array $changes): RecommendationPreference
	{
		$this->assertEnabled();
		$current = $this->preferences($userId);
		$errors = [];
		$enabled = $this->boolean($changes, 'enabled', $current->enabled, $errors);
		$consent = $this->boolean($changes, 'behavioral_consent', $current->behavioralConsent, $errors);
		$interests = $this->interests($changes, 'interests', $current->interests, $errors);
		$languages = $this->languages($changes, 'languages', $current->languages, $errors);

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$preference = $current->with(
			$enabled,
			$consent,
			$interests,
			$languages,
			Time::toSecondPrecision($this->clock->now()),
		);
		$this->store->savePreference($preference);

		// Withdrawing behavioral consent erases the retained signals instead
		// of merely muting them, so no derived influence survives the opt-out.
		if ($current->behavioralConsent && !$preference->behavioralConsent) {
			$this->store->deleteSignalsFor($userId);
		}

		return $preference;
	}

	/**
	 * @throws RecommendationDisabledException
	 * @throws InvalidRecommendationCursorException
	 */
	public function feed(string $userId, int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): RecommendationPage
	{
		$this->assertEnabled();
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$preference = $this->preferences($userId);
		$now = Time::toSecondPrecision($this->clock->now());

		if (!$preference->enabled) {
			return $this->emptyPage(false);
		}

		if ($cursor !== null && $cursor !== '') {
			$snapshot = $this->snapshot($userId, $cursor, $now);
			$feedback = $this->feedbackMap($userId);
			$offset = $this->cursorOffset($cursor, strlen($cursor));

			return $this->cursorPage($userId, $preference, $snapshot, $offset, $limit, $feedback, $now);
		}

		$window = max(40, min(self::MAX_CANDIDATES, $limit * 4));
		$candidates = $this->eligibility->filter(
			$userId,
			$this->candidates->candidates($userId, $window, $now),
			$preference,
		);
		$feedback = $this->feedbackMap($userId);
		$candidates = array_values(array_filter(
			$candidates,
			static fn ($candidate): bool => ($feedback[$candidate->id] ?? null)?->kind !== RecommendationFeedbackKind::Hide,
		));

		if ($candidates === []) {
			return $this->emptyPage(true);
		}

		$ranked = $this->diversify($this->ranker->rank(
			$userId,
			$candidates,
			$preference,
			$this->signalsForFeed($userId, $preference, $now),
			$feedback,
			$now,
		));
		$expiresAt = $now->modify('+' . self::SNAPSHOT_TTL_SECONDS . ' seconds');
		$snapshot = new RecommendationSnapshot(
			$this->snapshotId(),
			$userId,
			$ranked,
			$now,
			$expiresAt,
			RecommendationAlgorithm::VERSION,
		);
		$this->snapshots->put($snapshot);

		return $this->page($snapshot->id, $ranked, 0, $limit, $expiresAt, true);
	}

	/**
	 * @throws RecommendationDisabledException
	 * @throws RecommendationConsentRequiredException
	 * @throws RecommendationLimitException
	 */
	public function recordSignal(string $userId, RecommendationSignalKind $kind, string $candidateId): RecommendationSignal
	{
		$this->assertEnabled();
		$preference = $this->preferences($userId);
		if (!$preference->behavioralConsent) {
			throw new RecommendationConsentRequiredException();
		}

		$now = Time::toSecondPrecision($this->clock->now());

		// Passive events are noisy: one impression or click per candidate per
		// window is enough signal, repeats return the stored row unchanged.
		if ($kind === RecommendationSignalKind::Impression || $kind === RecommendationSignalKind::Click) {
			$existing = $this->store->recentSignal(
				$userId,
				$kind,
				$candidateId,
				$now->modify('-' . self::SIGNAL_NOISE_WINDOW_SECONDS . ' seconds'),
			);
			if ($existing !== null) {
				return $existing;
			}
		}

		if ($this->store->signalCount($userId) >= self::MAX_SIGNALS) {
			throw new RecommendationLimitException();
		}

		$signal = new RecommendationSignal(
			$this->ids->generate(),
			$userId,
			$kind,
			$candidateId,
			$now,
		);
		$this->store->appendSignal($signal);

		return $signal;
	}

	/**
	 * @throws RecommendationDisabledException
	 */
	public function recordFeedback(string $userId, string $candidateId, RecommendationFeedbackKind $kind): RecommendationFeedback
	{
		$this->assertEnabled();
		$feedback = new RecommendationFeedback(
			$userId,
			$candidateId,
			$kind,
			Time::toSecondPrecision($this->clock->now()),
		);
		$this->store->saveFeedback($feedback);

		return $feedback;
	}

	/**
	 * @throws RecommendationDisabledException
	 *
	 * @return array<string, mixed>
	 */
	public function export(string $userId): array
	{
		$this->assertEnabled();

		return [
			'algorithm_version' => RecommendationAlgorithm::VERSION,
			'preferences' => $this->preferences($userId)->toArray(),
			'feedback' => array_map(static fn (RecommendationFeedback $feedback): array => $feedback->toArray(), $this->store->feedbackFor($userId)),
			'signals' => array_map(static fn (RecommendationSignal $signal): array => $signal->toArray(), $this->store->signalsFor($userId, self::MAX_SIGNALS)),
		];
	}

	/**
	 * @throws RecommendationDisabledException
	 */
	public function reset(string $userId): void
	{
		$this->assertEnabled();
		$this->store->deleteForUser($userId);
	}

	/**
	 * @param list<RecommendationItem> $items
	 *
	 * @return list<RecommendationItem>
	 */
	private function diversify(array $items): array
	{
		$groups = [];
		foreach ($items as $item) {
			$groups[$item->candidate->authorId][] = $item;
		}

		$primary = [];
		$deferred = [];
		$served = array_fill_keys(array_keys($groups), 0);
		$position = array_fill_keys(array_keys($groups), 0);
		$lastAuthor = null;
		$run = 0;

		while ($groups !== []) {
			$chosen = null;
			foreach (array_keys($groups) as $author) {
				if ($served[$author] >= 3) {
					while (isset($groups[$author][$position[$author]])) {
						$deferred[] = $groups[$author][$position[$author]];
						++$position[$author];
					}
					unset($groups[$author]);
					continue;
				}

				if (!isset($groups[$author][$position[$author]])) {
					unset($groups[$author]);
					continue;
				}

				if ($author === $lastAuthor && $run >= 2) {
					continue;
				}

				$chosen = $author;
				break;
			}

			// Only a single remaining author can be blocked by the consecutive
			// rule; that is an underfilled pool, so it is allowed through rather
			// than dropping the item.
			if ($chosen === null) {
				foreach (array_keys($groups) as $author) {
					if ($served[$author] < 3 && isset($groups[$author][$position[$author]])) {
						$chosen = $author;
						break;
					}
				}
			}

			if ($chosen === null) {
				break;
			}

			$primary[] = $groups[$chosen][$position[$chosen]];
			++$position[$chosen];
			++$served[$chosen];
			if ($chosen === $lastAuthor) {
				++$run;
			} else {
				$lastAuthor = $chosen;
				$run = 1;
			}
		}

		// Rows beyond the per-author cap remain available for a later page or
		// an underfilled page, after every author has had a fair turn.
		foreach ($groups as $author => $remaining) {
			while (isset($remaining[$position[$author]])) {
				$deferred[] = $remaining[$position[$author]];
				++$position[$author];
			}
		}

		return array_merge($primary, $deferred);
	}

	/**
	 * Serves one cursor page out of a stored snapshot. Candidates are
	 * revalidated against their live records in a single bounded batch
	 * before anything is filtered or returned; a candidate whose source
	 * object was deleted, archived, narrowed or removed fails closed. A
	 * dropped candidate still consumes its snapshot position, so the raw
	 * cursor offset stays stable, and the walk only stops after `limit`
	 * presentable items or at the end of the snapshot.
	 *
	 * @param array<string, RecommendationFeedback> $feedback
	 */
	private function cursorPage(
		string $userId,
		RecommendationPreference $preference,
		RecommendationSnapshot $snapshot,
		int $offset,
		int $limit,
		array $feedback,
		DateTimeImmutable $now,
	): RecommendationPage {
		$total = count($snapshot->items);
		$live = $this->refresher?->refresh(
			$userId,
			array_values(array_map(
				static fn (RecommendationItem $item): RecommendationCandidate => $item->candidate,
				$snapshot->items,
			)),
			$now,
		);

		$aligned = [];
		foreach ($snapshot->items as $item) {
			$candidate = $live === null ? $item->candidate : ($live[$item->candidate->id] ?? null);
			if ($candidate === null) {
				$aligned[] = null;
				continue;
			}

			$aligned[] = $candidate === $item->candidate
				? $item
				: new RecommendationItem($candidate, $item->score, $item->reasons, $item->algorithmVersion);
		}

		$allowed = [];
		foreach ($this->presentable(
			$userId,
			$preference,
			array_values(array_filter($aligned)),
			$feedback,
		) as $item) {
			$allowed[$item->candidate->id] = true;
		}

		$pageItems = [];
		$consumed = 0;
		for ($i = $offset; $i < $total && count($pageItems) < $limit; ++$i) {
			++$consumed;
			$item = $aligned[$i];
			if ($item === null || !isset($allowed[$item->candidate->id])) {
				continue;
			}

			$pageItems[] = $item;
		}

		$nextOffset = $offset + $consumed;

		return new RecommendationPage(
			$pageItems,
			$nextOffset < $total ? $this->encodeCursor($snapshot->id, $nextOffset) : null,
			$snapshot->id,
			$snapshot->expiresAt,
			true,
			count($pageItems) < $limit,
			RecommendationAlgorithm::VERSION,
		);
	}

	/**
	 * @param list<RecommendationItem> $items
	 *
	 * @return list<RecommendationItem>
	 */
	private function presentable(
		string $userId,
		RecommendationPreference $preference,
		array $items,
		array $feedback,
	): array {
		$candidates = $this->eligibility->filter(
			$userId,
			array_values(array_map(static fn (RecommendationItem $item) => $item->candidate, $items)),
			$preference,
		);
		$allowed = [];
		foreach ($candidates as $candidate) {
			$verdict = $feedback[$candidate->id] ?? null;
			if ($verdict instanceof RecommendationFeedback && $verdict->kind === RecommendationFeedbackKind::Hide) {
				continue;
			}

			foreach ($items as $item) {
				if ($item->candidate->id === $candidate->id) {
					$allowed[] = $item;
					break;
				}
			}
		}

		return $allowed;
	}

	/**
	 * @param list<RecommendationItem> $items
	 */
	private function page(
		string $snapshotId,
		array $items,
		int $offset,
		int $limit,
		DateTimeImmutable $expiresAt,
		bool $enabled,
	): RecommendationPage {
		$total = count($items);
		if ($offset > $total) {
			throw new InvalidRecommendationCursorException('');
		}

		$pageItems = array_slice($items, $offset, $limit);
		$nextOffset = $offset + count($pageItems);

		return new RecommendationPage(
			$pageItems,
			$nextOffset < $total ? $this->encodeCursor($snapshotId, $nextOffset) : null,
			$snapshotId,
			$expiresAt,
			$enabled,
			count($pageItems) < $limit,
			RecommendationAlgorithm::VERSION,
		);
	}

	private function emptyPage(bool $enabled): RecommendationPage
	{
		return new RecommendationPage([], null, null, null, $enabled, !$enabled, RecommendationAlgorithm::VERSION);
	}

	/**
	 * @return array<string, RecommendationFeedback>
	 */
	private function feedbackMap(string $userId): array
	{
		$map = [];
		foreach ($this->store->feedbackFor($userId) as $feedback) {
			$map[$feedback->candidateId] = $feedback;
		}

		return $map;
	}

	/**
	 * Signals that may influence ranking right now: none without consent, and
	 * none older than the retention window.
	 *
	 * @return list<RecommendationSignal>
	 */
	private function signalsForFeed(string $userId, RecommendationPreference $preference, DateTimeImmutable $now): array
	{
		if (!$preference->behavioralConsent) {
			return [];
		}

		$cutoff = $now->modify('-' . self::SIGNAL_RETENTION_SECONDS . ' seconds');

		return array_values(array_filter(
			$this->store->signalsFor($userId, self::MAX_SIGNALS),
			static fn (RecommendationSignal $signal): bool => $signal->createdAt >= $cutoff,
		));
	}

	private function snapshotId(): string
	{
		return $this->ids->generate();
	}

	private function snapshot(string $userId, string $cursor, DateTimeImmutable $now): RecommendationSnapshot
	{
		$snapshotId = $this->cursorSnapshotId($cursor, strlen($cursor));
		$snapshot = $this->snapshots->get($snapshotId, $now);
		if ($snapshot === null || $snapshot->userId !== $userId || $snapshot->algorithmVersion !== RecommendationAlgorithm::VERSION) {
			throw new InvalidRecommendationCursorException($cursor);
		}

		return $snapshot;
	}

	private function encodeCursor(string $snapshotId, int $offset): string
	{
		try {
			$raw = json_encode(['v' => 1, 's' => $snapshotId, 'o' => $offset], JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new \LogicException('The recommendation cursor could not be encoded.', 0, $exception);
		}

		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private function cursorSnapshotId(string $cursor, int $length): string
	{
		return $this->decodeCursor($cursor, $length)['s'];
	}

	private function cursorOffset(string $cursor, int $length): int
	{
		return $this->decodeCursor($cursor, $length)['o'];
	}

	/**
	 * @return array{s: string, o: int}
	 */
	private function decodeCursor(string $cursor, int $length): array
	{
		if ($length < 1 || $length > self::MAX_CURSOR_LENGTH) {
			throw new InvalidRecommendationCursorException($cursor);
		}

		$padded = $cursor . str_repeat('=', (4 - ($length % 4)) % 4);
		$decoded = base64_decode(strtr($padded, '-_', '+/'), true);
		if ($decoded === false) {
			throw new InvalidRecommendationCursorException($cursor);
		}

		try {
			$payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new InvalidRecommendationCursorException($cursor);
		}

		if (!is_array($payload)
			|| ($payload['v'] ?? null) !== 1
			|| !is_string($payload['s'] ?? null)
			|| $payload['s'] === ''
			|| strlen($payload['s']) > 128
			|| !is_int($payload['o'] ?? null)
			|| $payload['o'] < 0
			|| $payload['o'] > self::MAX_CANDIDATES
		) {
			throw new InvalidRecommendationCursorException($cursor);
		}

		return ['s' => $payload['s'], 'o' => $payload['o']];
	}

	private function assertEnabled(): void
	{
		if (!$this->enabled()) {
			throw new RecommendationDisabledException();
		}
	}

	/**
	 * @param array<string, mixed> $changes
	 * @param array<string, string> $errors
	 */
	private function boolean(array $changes, string $field, bool $default, array &$errors): bool
	{
		if (!array_key_exists($field, $changes)) {
			return $default;
		}

		if (!is_bool($changes[$field])) {
			$errors[$field] = 'Expected a boolean.';

			return $default;
		}

		return $changes[$field];
	}

	/**
	 * @param array<string, mixed> $changes
	 * @param list<string> $current
	 * @param array<string, string> $errors
	 *
	 * @return list<string>
	 */
	private function interests(array $changes, string $field, array $current, array &$errors): array
	{
		if (!array_key_exists($field, $changes)) {
			return $current;
		}

		if (!is_array($changes[$field]) || !array_is_list($changes[$field])) {
			$errors[$field] = 'Expected a list of hashtags.';

			return $current;
		}

		$normalized = [];
		foreach ($changes[$field] as $value) {
			$tag = is_string($value) ? Hashtags::normalize($value) : null;
			if ($tag === null) {
				$errors[$field] = 'Every interest must be a valid hashtag.';

				return $current;
			}

			$normalized[$tag] = true;
		}

		if (count($normalized) > RecommendationPreference::MAX_INTERESTS) {
			$errors[$field] = 'At most ' . RecommendationPreference::MAX_INTERESTS . ' interests are allowed.';

			return $current;
		}

		return array_keys($normalized);
	}

	/**
	 * @param array<string, mixed> $changes
	 * @param list<string> $current
	 * @param array<string, string> $errors
	 *
	 * @return list<string>
	 */
	private function languages(array $changes, string $field, array $current, array &$errors): array
	{
		if (!array_key_exists($field, $changes)) {
			return $current;
		}

		if (!is_array($changes[$field]) || !array_is_list($changes[$field])) {
			$errors[$field] = 'Expected a list of language tags.';

			return $current;
		}

		$normalized = [];
		foreach ($changes[$field] as $value) {
			$language = is_string($value) ? mb_strtolower(trim($value)) : '';
			if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $language) !== 1) {
				$errors[$field] = 'Every language must be a valid lowercase language tag.';

				return $current;
			}

			$normalized[$language] = true;
		}

		if (count($normalized) > RecommendationPreference::MAX_LANGUAGES) {
			$errors[$field] = 'At most ' . RecommendationPreference::MAX_LANGUAGES . ' languages are allowed.';

			return $current;
		}

		return array_keys($normalized);
	}
}
