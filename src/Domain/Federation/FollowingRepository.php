<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Storage of the outgoing follow graph.
 *
 * A followed actor belongs to exactly one local user; the pair (local user,
 * actor) is unique, so following twice cannot duplicate the collection.
 * This is deliberately separate from {@see FollowerRepository}: that table
 * answers who follows the account, this one answers whom the account follows.
 */
interface FollowingRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the pair is already stored
	 */
	public function insert(FollowedActor $actor): void;

	public function find(string $localUserId, string $actorId): ?FollowedActor;

	/**
	 * Deletes only if the edge belongs to the given local user.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $localUserId, string $actorId): bool;

	/**
	 * The actor ids one local user follows, newest first.
	 *
	 * This is the following collection: all of them, not a page.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array;

	public function count(string $localUserId): int;
}
