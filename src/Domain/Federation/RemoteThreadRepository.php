<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Cache of objects fetched for a remote thread.
 *
 * This is deliberately separate from RemotePostRepository. A thread context
 * can contain an ancestor or reply whose Create activity was never delivered
 * to this instance, so it has no activity id and must not appear as a
 * standalone home-timeline row. The object id is unique and an upsert keeps a
 * refreshed snapshot without creating duplicates.
 */
interface RemoteThreadRepository
{
	public function upsert(RemoteThreadPost $post): void;

	public function find(string $objectId): ?RemoteThreadPost;

	/**
	 * Direct replies to one object, oldest first.
	 *
	 * @return list<RemoteThreadPost>
	 */
	public function children(string $parentObjectId, int $limit): array;

	/**
	 * Cached nodes older than a host-chosen retention boundary.
	 */
	public function countFetchedBefore(DateTimeImmutable $before): int;

	/**
	 * Removes at most `$limit` cached nodes older than the boundary, oldest
	 * fetch first with the object id as the tie-break.
	 *
	 * @return int number of removed rows
	 */
	public function deleteFetchedBefore(DateTimeImmutable $before, int $limit = 500): int;
}
