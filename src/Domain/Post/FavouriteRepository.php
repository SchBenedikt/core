<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * Storage of per-user post favourites.
 *
 * A row belongs to one local account and one post. There is no foreign key:
 * posts are soft-deleted, so the favourite can survive until a host cleanup
 * explicitly removes it. The repository only stores the relationship and the
 * public count.
 */
interface FavouriteRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the pair is already stored
	 */
	public function insert(Favourite $favourite): void;

	/**
	 * Deletes only the caller's favourite. Deleting an absent pair is a no-op.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $userId, string $postId): bool;

	public function exists(string $userId, string $postId): bool;

	public function countForPost(string $postId): int;

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, int> every requested id, with zero for no favourites
	 */
	public function countsForPosts(array $postIds): array;

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, bool> every requested id, with false for no favourite
	 */
	public function existsForPosts(string $userId, array $postIds): array;
}
