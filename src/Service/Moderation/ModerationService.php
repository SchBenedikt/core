<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Moderation;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Moderation\ModerationAction;
use AlohaSocial\Core\Domain\Moderation\ModerationActionKind;
use AlohaSocial\Core\Domain\Moderation\ModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\ModerationDecision;
use AlohaSocial\Core\Domain\Moderation\Report;
use AlohaSocial\Core\Domain\Moderation\ReportReason;
use AlohaSocial\Core\Domain\Moderation\ReportRepository;
use AlohaSocial\Core\Domain\Moderation\ReportStatus;
use AlohaSocial\Core\Domain\Moderation\ReportTargetType;
use AlohaSocial\Core\Domain\Moderation\StoredModerationPolicy;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Moderation\AdminAuthorization;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function count;
use function is_array;
use function is_string;
use function mb_substr;
use function trim;

/**
 * Host-neutral report review and instance policy actions.
 *
 * The host authenticates the caller; this service asks the
 * {@see AdminAuthorization} port whether that account is a moderator before it
 * queues, resolves, rejects or reverses anything. Submitting a report needs no
 * moderator: any authenticated local account may do it, and a remote actor may
 * submit one through a signed ActivityPub `Flag`.
 */
final class ModerationService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public function __construct(
		private readonly ReportRepository $reports,
		private readonly ModerationActionRepository $actions,
		private readonly AdminAuthorization $admins,
		private readonly PostService $posts,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ?LocalActorDirectory $localActors = null,
	) {
	}

	/**
	 * Files a report from a local account.
	 *
	 * @param list<string> $evidence
	 *
	 * @throws ValidationException when the target, reason or evidence is invalid
	 */
	public function submitReport(
		string $reporterId,
		string $targetType,
		string $targetId,
		string $reason,
		?string $comment = null,
		array $evidence = [],
	): Report {
		$type = ReportTargetType::fromInput($targetType);
		if ($type === null) {
			throw new ValidationException(['target_type' => 'target_type must be post, actor or domain.']);
		}

		$reasonValue = ReportReason::fromInput($reason);
		if ($reasonValue === null) {
			throw new ValidationException(['reason' => 'The report reason is not supported.']);
		}

		$reporterId = trim($reporterId);
		if ($reporterId === '') {
			throw new ValidationException(['reporter' => 'A report needs an authenticated reporter.']);
		}

		$targetId = $this->validatedTarget($reporterId, $type, $targetId);
		$comment = $this->normalizeText($comment);
		$evidence = $this->normalizeEvidence($evidence);

		$existing = $this->reports->findOpenByReporter($reporterId, $type, $targetId);
		if ($existing !== null) {
			return $existing;
		}

		return $this->store($reporterId, $type, $targetId, $reasonValue, $comment, $evidence);
	}

	/**
	 * Files a report carried by a signed ActivityPub `Flag`.
	 *
	 * Only the signer is trusted as the reporter; the object is treated as a
	 * target URL. A Flag never changes local state by itself and its evidence
	 * is bounded, so a peer cannot use it to dump content into the queue.
	 *
	 * @param mixed $object the `object` member of the Flag
	 */
	public function submitRemoteFlag(string $reporterActor, mixed $object, ?string $content = null): ?Report
	{
		$reporterActor = trim($reporterActor);
		if ($reporterActor === '') {
			return null;
		}

		$target = $this->flagTarget($object);
		if ($target === null) {
			return null;
		}

		$existing = $this->reports->findOpenByReporter($reporterActor, ReportTargetType::Actor, $target);
		if ($existing !== null) {
			return $existing;
		}

		$evidence = $content === null
			? []
			: [mb_substr(trim($content), 0, Report::MAX_EVIDENCE_LENGTH)];

		return $this->store(
			$reporterActor,
			ReportTargetType::Actor,
			$target,
			ReportReason::Other,
			'Reported through ActivityPub Flag.',
			array_values(array_filter($evidence, static fn (string $entry): bool => $entry !== '')),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function queue(string $moderatorId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		$this->requireModerator($moderatorId);

		return array_map(
			static fn (Report $report): array => $report->toArray(),
			$this->reports->queue($this->clamp($limit), $this->cursor($before)),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function reportsBy(string $reporterId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		return array_map(
			static fn (Report $report): array => $report->toArray(),
			$this->reports->forReporter(trim($reporterId), $this->clamp($limit), $this->cursor($before)),
		);
	}

	public function find(string $id): ?Report
	{
		return $this->reports->find($id);
	}

	/**
	 * Resolves a report and applies its policy action.
	 *
	 * A repeated resolve of a finished report is an idempotent read. If
	 * another moderator is already reviewing it, this is a conflict, not a
	 * second action.
	 */
	public function resolve(
		string $moderatorId,
		string $reportId,
		string $decision,
		?string $resolution = null,
	): Report {
		$this->requireModerator($moderatorId);
		$decisionValue = ModerationDecision::fromInput($decision);
		if ($decisionValue === null) {
			throw new ValidationException(['decision' => 'The moderation decision is not supported.']);
		}

		$report = $this->reports->find($reportId);
		if ($report === null) {
			throw new ModerationNotFoundException($reportId);
		}

		if (!$report->isOpen()) {
			return $report;
		}

		$at = Time::toSecondPrecision($this->clock->now());
		if (!$this->reports->claimForReview($reportId, $moderatorId, $at)) {
			$current = $this->reports->find($reportId);
			if ($current !== null && !$current->isOpen()) {
				return $current;
			}

			throw new ModerationConflictException($reportId);
		}

		$action = $this->applyDecision($moderatorId, $reportId, $report, $decisionValue, $resolution, $at);
		if ($action === null && $decisionValue !== ModerationDecision::NoAction) {
			$this->reports->release($reportId);

			throw new ValidationException(['target' => 'The report target cannot receive this action.']);
		}

		$note = $this->normalizeText($resolution);
		if (!$this->reports->complete(
			$reportId,
			ReportStatus::Resolved,
			$decisionValue,
			$moderatorId,
			$note,
			$at,
		)) {
			throw new ModerationConflictException($reportId);
		}

		$current = $this->reports->find($reportId);
		if ($current === null) {
			throw new ModerationNotFoundException($reportId);
		}

		return $current;
	}

	/**
	 * Closes a report without an action.
	 */
	public function reject(string $moderatorId, string $reportId, ?string $note = null): Report
	{
		$this->requireModerator($moderatorId);
		$report = $this->reports->find($reportId);
		if ($report === null) {
			throw new ModerationNotFoundException($reportId);
		}

		if (!$report->isOpen()) {
			return $report;
		}

		$at = Time::toSecondPrecision($this->clock->now());
		if (!$this->reports->claimForReview($reportId, $moderatorId, $at)) {
			$current = $this->reports->find($reportId);
			if ($current !== null && !$current->isOpen()) {
				return $current;
			}

			throw new ModerationConflictException($reportId);
		}

		if (!$this->reports->complete(
			$reportId,
			ReportStatus::Rejected,
			null,
			$moderatorId,
			$this->normalizeText($note),
			$at,
		)) {
			throw new ModerationConflictException($reportId);
		}

		$current = $this->reports->find($reportId);
		if ($current === null) {
			throw new ModerationNotFoundException($reportId);
		}

		return $current;
	}

	/**
	 * Reverses one active actor/domain policy action.
	 */
	public function reverseAction(string $moderatorId, string $actionId, string $note): ModerationAction
	{
		$this->requireModerator($moderatorId);
		$action = $this->actions->find($actionId);
		if ($action === null) {
			throw new ModerationNotFoundException($actionId);
		}

		if (!$action->isActive()) {
			return $action;
		}

		$note = trim($note);
		if ($note === '') {
			throw new ValidationException(['note' => 'A reversal needs a note.']);
		}

		$this->actions->reverse($actionId, $moderatorId, $note, Time::toSecondPrecision($this->clock->now()));

		return $this->actions->find($actionId) ?? $action;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function activeActions(string $moderatorId, ?string $kind = null, int $limit = 100): array
	{
		$this->requireModerator($moderatorId);
		$actionKind = $kind === null ? null : ModerationActionKind::tryFrom(trim($kind));

		return array_map(
			static fn (ModerationAction $action): array => $action->toArray(),
			$this->actions->active($actionKind, max(1, min(200, $limit))),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function actionHistory(string $moderatorId, string $target, int $limit = 100): array
	{
		$this->requireModerator($moderatorId);

		return array_map(
			static fn (ModerationAction $action): array => $action->toArray(),
			$this->actions->historyFor(trim($target), max(1, min(200, $limit))),
		);
	}

	public function countOpen(): int
	{
		return $this->reports->countOpen();
	}

	private function applyDecision(
		string $moderatorId,
		string $reportId,
		Report $report,
		ModerationDecision $decision,
		?string $resolution,
		DateTimeImmutable $at,
	): ?ModerationAction {
		$kind = ModerationActionKind::fromDecision($decision);
		if ($kind === null) {
			return null;
		}

		$target = $this->actionTarget($report, $decision);
		if ($target === null) {
			return null;
		}

		$existing = $this->actions->findActive($kind, $target);
		if ($existing !== null) {
			return $existing;
		}

		$action = new ModerationAction(
			id: $this->ids->generate(),
			kind: $kind,
			target: $target,
			reportId: $reportId,
			moderatorId: $moderatorId,
			note: $this->normalizeText($resolution),
			createdAt: $at,
		);

		$this->actions->insert($action);

		return $action;
	}

	private function actionTarget(Report $report, ModerationDecision $decision): ?string
	{
		$actorTarget = match ($report->targetType) {
			ReportTargetType::Actor => $report->targetId,
			ReportTargetType::Post => $this->postAuthor($report->targetId),
			ReportTargetType::Domain => null,
		};

		if ($decision === ModerationDecision::BlockDomain) {
			if ($report->targetType === ReportTargetType::Domain) {
				return StoredModerationPolicy::normalizeDomain($report->targetId);
			}

			return $actorTarget === null ? null : StoredModerationPolicy::domainOf($actorTarget);
		}

		return $actorTarget;
	}

	private function postAuthor(string $postId): ?string
	{
		try {
			return $this->posts->get($postId)->authorId;
		} catch (PostNotFoundException) {
			return null;
		}
	}

	private function validatedTarget(string $reporterId, ReportTargetType $type, string $targetId): string
	{
		$targetId = trim($targetId);
		if ($targetId === '') {
			throw new ValidationException(['target_id' => 'A report needs a target.']);
		}

		if ($type === ReportTargetType::Post) {
			try {
				$post = $this->posts->getVisible($targetId, $reporterId);
			} catch (PostNotFoundException) {
				throw new ValidationException(['target_id' => 'The reported post does not exist or is not visible.']);
			}

			if ($post->authorId === $reporterId) {
				throw new ValidationException(['target_id' => 'You cannot report your own post.']);
			}

			return $post->id;
		}

		if ($type === ReportTargetType::Domain) {
			$domain = StoredModerationPolicy::normalizeDomain($targetId);
			if ($domain === null) {
				throw new ValidationException(['target_id' => 'The reported domain is not valid.']);
			}

			return $domain;
		}

		if ($this->localActors?->has($targetId) === true) {
			if ($targetId === $reporterId) {
				throw new ValidationException(['target_id' => 'You cannot report yourself.']);
			}

			return $targetId;
		}

		$domain = StoredModerationPolicy::domainOf($targetId);
		if ($domain === null) {
			throw new ValidationException(['target_id' => 'A remote actor target must be an http(s) actor URL.']);
		}

		return $targetId;
	}

	private function flagTarget(mixed $object): ?string
	{
		if (is_string($object)) {
			$object = trim($object);

			return StoredModerationPolicy::domainOf($object) === null ? null : $object;
		}

		if (!is_array($object)) {
			return null;
		}

		foreach (['id', 'href', 'url'] as $key) {
			$value = $object[$key] ?? null;
			if (is_string($value) && StoredModerationPolicy::domainOf($value) !== null) {
				return trim($value);
			}
		}

		$items = isset($object[0]) && is_array($object[0]) ? $object[0] : null;
		if ($items !== null) {
			return $this->flagTarget($items);
		}

		return null;
	}

	/**
	 * @param list<string> $evidence
	 */
	private function normalizeEvidence(array $evidence): array
	{
		if (count($evidence) > Report::MAX_EVIDENCE_ITEMS) {
			throw new ValidationException(['evidence' => 'Too many evidence entries.']);
		}

		$normalized = [];
		foreach ($evidence as $entry) {
			if (!is_string($entry)) {
				throw new ValidationException(['evidence' => 'Evidence entries must be strings.']);
			}

			$entry = trim($entry);
			if ($entry === '') {
				continue;
			}

			$normalized[] = mb_substr($entry, 0, Report::MAX_EVIDENCE_LENGTH);
		}

		return $normalized;
	}

	private function store(
		string $reporterId,
		ReportTargetType $type,
		string $targetId,
		ReportReason $reason,
		?string $comment,
		array $evidence,
	): Report {
		$now = Time::toSecondPrecision($this->clock->now());
		$report = new Report(
			id: $this->ids->generate(),
			reporterId: $reporterId,
			targetType: $type,
			targetId: $targetId,
			reason: $reason,
			comment: $comment,
			evidence: $evidence,
			status: ReportStatus::Pending,
			decision: null,
			moderatorId: null,
			resolution: null,
			createdAt: $now,
			updatedAt: $now,
		);

		$this->reports->insert($report);

		return $report;
	}

	private function normalizeText(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}

	private function requireModerator(string $userId): void
	{
		if (!$this->admins->isModerator($userId)) {
			throw new ModerationForbiddenException();
		}
	}

	private function clamp(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	private function cursor(?string $before): ?DateTimeImmutable
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		return Time::parse(trim($before));
	}
}
