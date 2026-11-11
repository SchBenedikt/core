<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/**
 * Storage of posts.
 *
 * Timelines are newest first, with the post id as the tie-breaker, so paging
 * is stable even when two posts share a second. `$before` is exclusive.
 */
interface PostRepository
{
	/**
	 * Inserts a new post.
	 *
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is taken
	 */
	public function insert(Post $post): void;

	public function find(string $id): ?Post;

	/**
	 * Marks a post as deleted. Deleting an unknown id is a no-op.
	 */
	public function softDelete(string $id, DateTimeImmutable $deletedAt): void;

	/**
	 * Everything the public timelines may show.
	 *
	 * @return list<Post>
	 */
	public function publicTimeline(int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * The signed-in user's home timeline.
	 *
	 * For now: the user's own posts plus everything publicly visible. When
	 * the follow graph lands, this becomes "followed authors plus own posts"
	 * without changing the interface.
	 *
	 * @return list<Post>
	 */
	public function homeTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array;
}
