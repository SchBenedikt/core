<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
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
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		?DateTimeImmutable $before = null,
	): array {
		$entries = [];

		foreach ($this->posts->ownTimeline($localUserId, self::UNBOUNDED, $before) as $post) {
			$entries[] = $this->fromPost($post);
		}

		foreach ($this->remotePosts->timelineForActors(
			$this->following->actorIds($localUserId),
			self::UNBOUNDED,
			$before,
		) as $post) {
			$entries[] = $this->fromRemotePost($post);
		}

		usort($entries, static function (TimelineEntry $a, TimelineEntry $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($entries, 0, max(1, $limit));
	}

	private function fromPost(Post $post): TimelineEntry
	{
		return new TimelineEntry(
			id: $post->id,
			authorId: $post->authorId,
			objectId: $post->id,
			objectAuthorId: $post->authorId,
			content: $post->content,
			contentWarning: $post->contentWarning,
			publishedAt: $post->publishedAt,
			visibility: $post->visibility,
		);
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
		);
	}
}
