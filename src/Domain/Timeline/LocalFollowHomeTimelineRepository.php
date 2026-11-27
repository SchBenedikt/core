<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Service\LocalRelationshipService;
use DateTimeImmutable;

use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
use function usort;

/**
 * Adds local posts from local accounts the reader follows.
 *
 * The base repository already owns the cursor and the remote/own-post merge.
 * This decorator fetches its top page plus the top page of locally followed
 * authors, sorts the union with the same tie-break and slices once, so a local
 * post older than the remote page cannot jump ahead of newer content. The
 * followed-author query excludes direct posts; a direct post is already in the
 * base page when the reader is its author or recipient, and a follower edge
 * alone must not expose it.
 *
 * With no local follows it delegates unchanged and costs one empty lookup.
 */
final readonly class LocalFollowHomeTimelineRepository implements HomeTimelineRepository
{
	public function __construct(
		private HomeTimelineRepository $base,
		private PostRepository $posts,
		private LocalRelationshipService $relationships,
		private ?BoostRepository $boosts = null,
		private ?MuteRepository $mutes = null,
		private ?Clock $clock = null,
		private ?AccountListRepository $lists = null,
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		DateTimeImmutable|TimelineCursor|null $before = null,
		bool $hideReplies = false,
	): array {
		$entries = $this->base->forUser($localUserId, $limit, $before, $hideReplies);
		// The base timeline may already include the reader's own boosts (and,
		// depending on its adapter, posts from local follows). Keep the wrapper
		// additions idempotent by timeline item ID.
		$entryIds = array_fill_keys(array_map(
			static fn (TimelineEntry $entry): string => $entry->id,
			$entries,
		), true);
		$representedObjects = array_fill_keys(array_map(
			static fn (TimelineEntry $entry): string => $entry->objectId,
			$entries,
		), true);
		$muted = array_fill_keys(
			$this->mutes?->actorIds($localUserId, ($this->clock ?? new SystemClock())->now()) ?? [],
			true,
		);
		$blocked = array_fill_keys($this->relationships->blockedAccountIds($localUserId), true);
		$exclusive = array_fill_keys($this->lists?->exclusiveActorIds($localUserId) ?? [], true);
		$authors = array_values(array_filter(
			$this->relationships->localFollowingUserIds($localUserId),
			static fn (string $authorId): bool => !isset($blocked[$authorId])
				&& !isset($muted[$authorId])
				&& !isset($exclusive[$authorId]),
		));

		if ($authors !== []) {
			foreach ($this->posts->timelineForAuthors($authors, $limit, $before, $hideReplies) as $post) {
				if (isset($muted[$post->authorId])) {
					continue;
				}
				if (isset($entryIds[$post->id])) {
					continue;
				}

				$entries[] = TimelineEntry::fromPost($post);
				$entryIds[$post->id] = true;
				$representedObjects[$post->id] = true;
			}
		}

		// The base repository owns the reader's own posts and boosts. Query only
		// followed local boosters here; otherwise their rejected own boosts can
		// consume this page before it ever reaches an eligible followed boost.
		$boostAuthors = $authors;
		$boostBatchSize = max(20, $limit);
		$boostCursor = $before;
		$visibleBoosts = 0;
		while ($boostAuthors !== [] && $this->boosts !== null && $visibleBoosts < max(1, $limit)) {
			$boostPage = $this->boosts->timelineForUsers($boostAuthors, $boostBatchSize, $boostCursor);
			if ($boostPage === []) {
				break;
			}

			$lastBoost = $boostPage[array_key_last($boostPage)];
			$nextBoostCursor = new TimelineCursor($lastBoost->createdAt, $lastBoost->id);
			if (!$this->cursorAdvances($boostCursor, $nextBoostCursor)) {
				break;
			}
			$boostCursor = $nextBoostCursor;

			foreach ($boostPage as $boost) {
				if (isset($entryIds[$boost->id]) || isset($representedObjects[$boost->postId])) {
					continue;
				}
				$post = $this->posts->find($boost->postId);
				if ($post === null || $post->isDeleted() || !$post->visibility->isPubliclyVisible()) {
					continue;
				}
				if ($hideReplies && $post->replyParentRef() !== null) {
					continue;
				}

				if (isset($muted[$boost->userId]) || isset($muted[$post->authorId])) {
					continue;
				}
				if (isset($blocked[$boost->userId]) || isset($blocked[$post->authorId])) {
					continue;
				}
				if (isset($exclusive[$boost->userId]) || isset($exclusive[$post->authorId])) {
					continue;
				}

				$entries[] = new TimelineEntry(
					id: $boost->id,
					authorId: $boost->userId,
					objectId: $post->id,
					objectAuthorId: $post->authorId,
					content: $post->content,
					contentWarning: $post->contentWarning,
					publishedAt: $boost->createdAt,
					visibility: $post->visibility,
					boostedBy: $boost->userId,
					inReplyToId: $post->replyParentRef(),
				);
				$entryIds[$boost->id] = true;
				$representedObjects[$post->id] = true;
				++$visibleBoosts;
				if ($visibleBoosts >= max(1, $limit)) {
					break;
				}
			}

			if (count($boostPage) < $boostBatchSize) {
				break;
			}
		}

		usort($entries, static function (TimelineEntry $a, TimelineEntry $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return TimelineEntryDeduplicator::deduplicateBoosts(array_slice($entries, 0, max(1, $limit)));
	}

	private function cursorAdvances(DateTimeImmutable|TimelineCursor|null $current, TimelineCursor $next): bool
	{
		if ($current === null) {
			return true;
		}

		$currentTime = $current instanceof TimelineCursor ? $current->publishedAt : $current;
		if ($next->publishedAt < $currentTime) {
			return true;
		}

		return $current instanceof TimelineCursor
			&& $next->publishedAt == $currentTime
			&& strcmp($next->id, $current->id) < 0;
	}
}
