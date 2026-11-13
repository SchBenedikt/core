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
	) {
	}

	public function forUser(
		string $localUserId,
		int $limit,
		?DateTimeImmutable $before = null,
	): array {
		$entries = $this->base->forUser($localUserId, $limit, $before);
		$muted = array_fill_keys(
			$this->mutes?->actorIds($localUserId, ($this->clock ?? new SystemClock())->now()) ?? [],
			true,
		);
		$authors = array_values(array_filter(
			$this->relationships->localFollowingUserIds($localUserId),
			static fn (string $authorId): bool => !isset($muted[$authorId]),
		));

		if ($authors !== []) {
			foreach ($this->posts->timelineForAuthors($authors, $limit, $before) as $post) {
				if (isset($muted[$post->authorId])) {
					continue;
				}

				$entries[] = TimelineEntry::fromPost($post);
			}
		}

		// The reader's own boosts belong on their home timeline even when
		// they do not follow another local account yet.
		$boostAuthors = $authors;
		$boostAuthors[] = $localUserId;
		$boostAuthors = array_values(array_filter(
			array_unique($boostAuthors),
			static fn (string $authorId): bool => !isset($muted[$authorId]),
		));
		foreach ($this->boosts?->timelineForUsers($boostAuthors, $limit, $before) ?? [] as $boost) {
			$post = $this->posts->find($boost->postId);
			if ($post === null || $post->isDeleted() || !$post->visibility->isPubliclyVisible()) {
				continue;
			}

			if (isset($muted[$boost->userId]) || isset($muted[$post->authorId])) {
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
