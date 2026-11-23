<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/**
 * Storage of local boosts.
 *
 * A boost is independent of the post row: deleting the post does not erase
 * the historical share, and a host cleanup may remove boosts of tombstones
 * later. The (user, post) pair is unique.
 */
interface BoostRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id or pair is already stored
	 */
	public function insert(Boost $boost): void;

	public function find(string $id): ?Boost;

	public function findByUserAndPost(string $userId, string $postId): ?Boost;

	/**
	 * Deletes only one account's boost. Removing an absent pair is a no-op.
	 */
	public function remove(string $userId, string $postId): bool;

	public function exists(string $userId, string $postId): bool;

	public function countForPost(string $postId): int;

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, int> every requested id, with zero for no boosts
	 */
	public function countsForPosts(array $postIds): array;

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, bool> every requested id, with false for no boost
	 */
	public function existsForPosts(string $userId, array $postIds): array;

	/**
	 * Newest boosts by a set of local accounts, for the home timeline.
	 *
	 * @param list<string> $userIds
	 *
	 * @return list<Boost>
	 */
	public function timelineForUsers(array $userIds, int $limit, DateTimeImmutable|TimelineCursor|null $before = null): array;
}
