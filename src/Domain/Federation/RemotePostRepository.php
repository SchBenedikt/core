<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Storage of posts and boosts received from remote actors.
 *
 * The activity id is the primary key, so a duplicate delivery cannot create a
 * second timeline row. Deletes are soft: a tombstone keeps the activity id
 * claimed and lets the home timeline filter the object without losing the
 * ability to answer a later duplicate.
 */
interface RemotePostRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the activity id already exists
	 */
	public function insert(RemotePost $post): void;

	/**
	 * Looks up a row including a soft-deleted one, for duplicate detection.
	 */
	public function findByActivityId(string $activityId): ?RemotePost;

	/**
	 * The live post for an object id; boosts are deliberately excluded.
	 */
	public function findPostByObjectId(string $objectId): ?RemotePost;

	/**
	 * Soft-deletes one activity if the actor owns it.
	 *
	 * @return bool whether a live row was removed
	 */
	public function softDeleteByActivityId(
		string $activityId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool;

	/**
	 * Soft-deletes the live post for an object if the actor owns it.
	 *
	 * @return bool whether a live row was removed
	 */
	public function softDeletePostByObjectId(
		string $objectId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool;

	/**
	 * Live posts and boosts by the given actors, newest first.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return list<RemotePost>
	 */
	public function timelineForActors(
		array $actorIds,
		int $limit,
		?DateTimeImmutable $before = null,
	): array;
}
