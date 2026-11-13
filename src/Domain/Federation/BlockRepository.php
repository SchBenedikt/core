<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Storage of per-user actor blocks.
 *
 * A block belongs to exactly one local user; the pair (local user, actor) is
 * unique, so blocking twice cannot create two rows. Blocks are not shared
 * between accounts: one reader's block must never hide an actor from another
 * reader's timeline.
 */
interface BlockRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the pair is already stored
	 */
	public function insert(Block $block): void;

	public function findByActor(string $localUserId, string $actorId): ?Block;

	public function findById(string $id): ?Block;

	/**
	 * Deletes only if the block belongs to the given local user.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $id, string $localUserId): bool;

	/**
	 * Newest blocks first, with the id as the tie-break.
	 *
	 * @return list<Block>
	 */
	public function blocks(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * The actors one local user blocks, newest first.
	 *
	 * This is what the home timeline uses to suppress remote content.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array;

	public function count(string $localUserId): int;
}
