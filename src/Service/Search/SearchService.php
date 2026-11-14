<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Search;

use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Domain\Search\InvalidSearchCursorException;
use AlohaSocial\Core\Domain\Search\RemoteActorSearch;
use AlohaSocial\Core\Domain\Search\RemoteHandle;
use AlohaSocial\Core\Domain\Search\RemoteSearchException;
use AlohaSocial\Core\Domain\Search\SearchCursor;
use AlohaSocial\Core\Domain\Search\SearchIndex;
use AlohaSocial\Core\Domain\Search\SearchKind;
use AlohaSocial\Core\Domain\Search\SearchRecord;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Domain\Search\TrendRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;

use function array_keys;
use function array_map;
use function array_values;
use function in_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function parse_url;
use function strtolower;
use function trim;

/**
 * Neutral, bounded search over the local discovery index plus exact remote
 * handle lookup.
 *
 * The service never scans posts or actors on a read. A handle query may make
 * one WebFinger plus one actor request, through the bounded federation
 * fetcher; every other query is served from the index. A remote failure
 * degrades to cached results with `partial=true` instead of pretending the
 * Fediverse is empty.
 */
final class SearchService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public const MAX_QUERY_LENGTH = 200;

	public const REMOTE_ACTOR_TTL_SECONDS = 3600;

	public const DEFAULT_TREND_WINDOW_SECONDS = 604800;

	public const MAX_TREND_WINDOW_SECONDS = 2592000;

	private const TYPES = [null, 'accounts', 'statuses', 'hashtags'];

	public function __construct(
		private readonly SearchIndex $index,
		private readonly Clock $clock,
		private readonly ?RemoteActorSearch $remoteActors = null,
		private readonly ?TrendRepository $trends = null,
		private readonly ?LocalRelationshipService $relationships = null,
		private readonly ?MuteService $mutes = null,
		private readonly ?ModerationPolicy $moderation = null,
	) {
	}

	/**
	 * @return array{
	 *   query: string,
	 *   accounts: list<array<string, mixed>>,
	 *   statuses: list<array<string, mixed>>,
	 *   hashtags: list<array{hashtag: string, count: int}>,
	 *   next_cursor: ?string,
	 *   partial: bool,
	 *   remote_error: ?string
	 * }
	 */
	public function search(
		?string $viewerId,
		string $query,
		?string $type,
		int $limit,
		?string $cursor,
	): array {
		$query = trim($query);
		if ($query === '') {
			throw new InvalidArgumentException('The search query is required.');
		}

		if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
			throw new InvalidArgumentException('The search query is too long.');
		}

		$type = $type === null ? null : strtolower(trim($type));
		if ($type === '') {
			$type = null;
		}

		if (!in_array($type, self::TYPES, true)) {
			throw new InvalidArgumentException('The search type must be accounts, statuses or hashtags.');
		}

		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$cursorValue = SearchCursor::decode($cursor, $query);
		$excluded = $this->excludedAuthors($viewerId);
		$kinds = $this->kinds($type);
		$partial = false;
		$remoteError = null;

		if ($cursorValue === null
			&& $this->remoteActors !== null
			&& ($type === null || $type === 'accounts')
		) {
			$handle = RemoteHandle::tryFrom($query);
			if ($handle !== null) {
				[$partial, $remoteError] = $this->refreshRemoteActor($handle);
			}
		}

		$page = $this->index->search($query, $kinds, $limit, $cursorValue, $excluded);

		$records = [];
		foreach ($page->records as $record) {
			if ($this->allowedByModeration($record)) {
				$records[] = $record;
			}
		}

		$accounts = [];
		$statuses = [];
		foreach ($records as $record) {
			if ($record->kind === SearchKind::Account) {
				$accounts[] = $record->toArray();
			} elseif ($record->kind === SearchKind::Post) {
				$statuses[] = $record->toArray();
			}
		}

		$hashtags = [];
		if ($this->trends !== null && ($type === null || $type === 'hashtags')) {
			$since = Time::toSecondPrecision(
				$this->clock->now()->modify('-' . self::DEFAULT_TREND_WINDOW_SECONDS . ' seconds'),
			);
			foreach ($this->trends->top($since, $limit, $query) as $trend) {
				$hashtags[] = $trend->toArray();
			}
		}

		return [
			'query' => $query,
			'accounts' => $accounts,
			'statuses' => $statuses,
			'hashtags' => $hashtags,
			'next_cursor' => $page->nextCursor?->encode(),
			'partial' => $partial,
			'remote_error' => $remoteError,
		];
	}

	/**
	 * @return array{window_seconds: int, trends: list<array{hashtag: string, count: int}>}
	 */
	public function trends(int $limit, int $windowSeconds = self::DEFAULT_TREND_WINDOW_SECONDS): array
	{
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$windowSeconds = max(60, min(self::MAX_TREND_WINDOW_SECONDS, $windowSeconds));
		if ($this->trends === null) {
			return ['window_seconds' => $windowSeconds, 'trends' => []];
		}

		$since = Time::toSecondPrecision($this->clock->now()->modify('-' . $windowSeconds . ' seconds'));

		return [
			'window_seconds' => $windowSeconds,
			'trends' => array_map(
				static fn ($trend): array => $trend->toArray(),
				$this->trends->top($since, $limit),
			),
		];
	}

	/**
	 * @return array{0: bool, 1: ?string} partial flag and stable remote reason
	 */
	private function refreshRemoteActor(RemoteHandle $handle): array
	{
		$cached = $this->cachedRemoteActor($handle);
		$now = Time::toSecondPrecision($this->clock->now());
		if ($cached !== null
			&& $now->getTimestamp() - $cached->cachedAt->getTimestamp() <= self::REMOTE_ACTOR_TTL_SECONDS
		) {
			return [false, null];
		}

		try {
			$profile = $this->remoteActors?->lookup($handle);
		} catch (RemoteSearchException $exception) {
			// Even with a stale cached row the answer is partial: the client
			// must not present an old remote profile as freshly confirmed.
			return [true, $exception->reason];
		}

		if ($profile === null) {
			return [true, 'not_found'];
		}

		$record = $profile->searchRecord(SearchSource::Remote, $now);
		$this->index->upsert($record);
		if ($cached !== null && $cached->id !== $record->id) {
			$this->index->remove($cached->id);
		}

		return [false, null];
	}

	private function cachedRemoteActor(RemoteHandle $handle): ?SearchRecord
	{
		if ($this->remoteActors === null) {
			return null;
		}

		$page = $this->index->search($handle->normalized(), [SearchKind::Account], 5);
		$needles = [$handle->normalized(), '@' . $handle->normalized()];
		foreach ($page->records as $record) {
			foreach ($needles as $needle) {
				if (in_array($needle, $record->aliases, true)) {
					return $record;
				}
			}
		}

		return null;
	}

	/**
	 * @return list<SearchKind>
	 */
	private function kinds(?string $type): array
	{
		return match ($type) {
			'accounts' => [SearchKind::Account],
			'statuses' => [SearchKind::Post],
			'hashtags' => [SearchKind::Hashtag],
			default => [SearchKind::Account, SearchKind::Post],
		};
	}

	/**
	 * @return list<string>
	 */
	private function excludedAuthors(?string $viewerId): array
	{
		if ($viewerId === null) {
			return [];
		}

		$excluded = [];
		foreach ($this->relationships?->blockedAccountIds($viewerId) ?? [] as $actorId) {
			$excluded[$actorId] = true;
		}

		foreach ($this->mutes?->actorIds($viewerId) ?? [] as $actorId) {
			$excluded[$actorId] = true;
		}

		return array_values(array_keys($excluded));
	}

	private function allowedByModeration(SearchRecord $record): bool
	{
		if ($this->moderation === null || $record->authorId === null) {
			return true;
		}

		if (!$this->moderation->allowsActor($record->authorId)) {
			return false;
		}

		$host = parse_url($record->authorId, PHP_URL_HOST);

		return !is_string($host) || $this->moderation->allowsDomain($host);
	}
}
