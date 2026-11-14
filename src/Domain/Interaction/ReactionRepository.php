<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

/**
 * Storage of federated reactions in both directions.
 *
 * The repository is deliberately not a "counts cache": counts are derived
 * from the rows, so an Undo always removes the same row a count added. A
 * duplicate identity is a storage error; the caller treats it as "already
 * applied" after re-reading, which is what makes a retransmitted Like
 * idempotent.
 */
interface ReactionRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the identity already exists
	 */
	public function insert(Reaction $reaction): void;

	/**
	 * Deletes one identity. Removing an absent reaction is a no-op.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): bool;

	public function find(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): ?Reaction;

	/**
	 * Resolves an `Undo` that embeds only the reaction activity id. Includes
	 * removed rows only if the adapter never deletes; implementations may
	 * return null after an Undo, and callers treat that as already undone.
	 */
	public function findByActivityId(string $activityId): ?Reaction;

	/**
	 * Inbound counts for local posts, keyed by local post id.
	 *
	 * @param list<string> $localPostIds
	 *
	 * @return array<string, array{likes: int, emojis: array<string, int>}> every requested id
	 */
	public function countsForPosts(array $localPostIds): array;

	/**
	 * Outbound reactions of one subject for several remote objects.
	 *
	 * @param list<string> $objectIds
	 *
	 * @return array<string, list<Reaction>> every requested object id
	 */
	public function forSubject(ReactionDirection $direction, string $subjectId, array $objectIds): array;
}
