<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/**
 * Storage of private per-user post bookmarks.
 *
 * A bookmark belongs to one local account and one post. There is no foreign
 * key because posts are soft-deleted; the row can survive until a host cleanup
 * removes it. Only the owner can list or remove a bookmark.
 */
interface BookmarkRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the pair is already stored
	 */
	public function insert(Bookmark $bookmark): void;

	/**
	 * Deletes only the caller's bookmark. Deleting an absent pair is a no-op.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $userId, string $postId): bool;

	public function exists(string $userId, string $postId): bool;

	/**
	 * The stored bookmark for one owner and post, if any.
	 */
	public function find(string $userId, string $postId): ?Bookmark;

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, bool> every requested id, with false for no bookmark
	 */
	public function existsForPosts(string $userId, array $postIds): array;

	/**
	 * Newest bookmarks by one owner, with the post id as the tie-break.
	 *
	 * The before/after pair is an exclusive cursor on (created_at, post_id).
	 *
	 * @return list<Bookmark>
	 */
	public function forUser(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforePostId = null,
		?DateTimeImmutable $after = null,
		?string $afterPostId = null,
	): array;
}
