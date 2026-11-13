<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Service\LocalRelationshipService;
use DateTimeImmutable;

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
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		?DateTimeImmutable $before = null,
	): array {
		$authors = $this->relationships->localFollowingUserIds($localUserId);
		$entries = $this->base->forUser($localUserId, $limit, $before);

		if ($authors !== []) {
			foreach ($this->posts->timelineForAuthors($authors, $limit, $before) as $post) {
				$entries[] = TimelineEntry::fromPost($post);
			}
		}

		// The reader's own boosts belong on their home timeline even when
		// they do not follow another local account yet.
		$boostAuthors = $authors;
		$boostAuthors[] = $localUserId;
		foreach ($this->boosts?->timelineForUsers(array_values(array_unique($boostAuthors)), $limit, $before) ?? [] as $boost) {
			$post = $this->posts->find($boost->postId);
			if ($post === null || $post->isDeleted() || !$post->visibility->isPubliclyVisible()) {
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
			);
		}

		usort($entries, static function (TimelineEntry $a, TimelineEntry $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($entries, 0, max(1, $limit));
	}
}
