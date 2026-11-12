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
	 * Every publicly visible post of one author, newest first, for the outbox.
	 *
	 * Only public and unlisted posts are returned: the outbox is a public
	 * endpoint, and listing a followers-only post there would leak it to
	 * anyone who can guess the actor URL.
	 *
	 * @return list<Post>
	 */
	public function authorPosts(string $userId): array;

	/**
	 * One page of the same public-author collection, newest first.
	 *
	 * @return list<Post>
	 */
	public function authorPostsPage(string $userId, int $limit, int $offset): array;

	/**
	 * The number of rows {@see authorPostsPage()} pages over.
	 */
	public function authorPostCount(string $userId): int;

	/**
	 * Every live post of one local user, including non-public ones.
	 *
	 * The combined home timeline uses this for the reader's own posts; the
	 * remote half comes from {@see \AlohaSocial\Core\Domain\Federation\RemotePostRepository}.
	 *
	 * @return list<Post>
	 */
	public function ownTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array;
}
