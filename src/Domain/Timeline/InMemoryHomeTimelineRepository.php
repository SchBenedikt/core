<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use DateTimeImmutable;

/**
 * The combined timeline in memory, for tests and the reference assembly.
 *
 * It reads both repositories whole and then applies one sort, so a tie on the
 * published second is broken by the same id rule as the SQL query.
 */
final class InMemoryHomeTimelineRepository implements HomeTimelineRepository
{
	private const UNBOUNDED = PHP_INT_MAX;

	public function __construct(
		private readonly PostRepository $posts,
		private readonly RemotePostRepository $remotePosts,
		private readonly FollowingRepository $following,
		private readonly BlockRepository $blocks,
		private readonly ?MuteRepository $mutes = null,
		private readonly ?Clock $clock = null,
		private readonly ?BoostRepository $boosts = null,
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		DateTimeImmutable|TimelineCursor|null $before = null,
		bool $hideReplies = false,
	): array {
		$entries = [];
		$muted = array_fill_keys(
			$this->mutes?->actorIds($localUserId, ($this->clock ?? new SystemClock())->now()) ?? [],
			true,
		);

		foreach ($this->posts->ownTimeline($localUserId, self::UNBOUNDED, $before) as $post) {
			if ($hideReplies && $post->replyParentRef() !== null) {
				continue;
			}
			if (isset($muted[$post->authorId])) {
				continue;
			}

			$entries[] = TimelineEntry::fromPost($post);
		}

		$blocked = array_fill_keys($this->blocks->actorIds($localUserId), true);
		foreach ($this->boosts?->timelineForUsers([$localUserId], self::UNBOUNDED, $before) ?? [] as $boost) {
			$post = $this->posts->find($boost->postId);
			if ($post === null
				|| $post->isDeleted()
				|| $post->archivedAt !== null
				|| !in_array($post->visibility, [Visibility::Public, Visibility::Unlisted], true)
				|| ($hideReplies && $post->replyParentRef() !== null)
				|| isset($blocked[$post->authorId])
				|| isset($muted[$post->authorId])
			) {
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
		}

		foreach ($this->remotePosts->timelineForActors(
			$this->following->actorIds($localUserId),
			self::UNBOUNDED,
			$before,
		) as $post) {
			if ($hideReplies && $post->inReplyToId !== null) {
				continue;
			}
			if (isset($blocked[$post->actorId]) || isset($muted[$post->actorId])) {
				continue;
			}

			// A boost can carry content by somebody other than the booster;
			// blocking or muting that author must hide the boosted content too.
			if ($post->objectAuthorId !== null
				&& (isset($blocked[$post->objectAuthorId]) || isset($muted[$post->objectAuthorId]))
			) {
				continue;
			}

			$entries[] = $this->fromRemotePost($post);
		}

		usort($entries, static function (TimelineEntry $a, TimelineEntry $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return TimelineEntryDeduplicator::deduplicateBoosts(array_slice($entries, 0, max(1, $limit)));
	}

	private function fromRemotePost(RemotePost $post): TimelineEntry
	{
		return new TimelineEntry(
			id: $post->activityId,
			authorId: $post->actorId,
			objectId: $post->objectId,
			objectAuthorId: $post->objectAuthorId,
			content: $post->content,
			contentWarning: $post->contentWarning,
			publishedAt: $post->publishedAt,
			boostedBy: $post->kind === RemotePostKind::Boost ? $post->actorId : null,
			inReplyToId: $post->inReplyToId,
		);
	}
}
