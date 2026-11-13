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

	public function findById(string $id): ?FollowedActor;

	/**
	 * Finds the edge whose exact Follow activity id is named by an
	 * Accept/Reject. A row from before the lifecycle has no activity id and
	 * is therefore never matched by a response.
	 */
	public function findByActivityId(string $activityId): ?FollowedActor;

	/**
	 * Replaces the stored inbox of an existing edge, keeping its identity.
	 */
	public function updateInbox(FollowedActor $actor): void;

	/**
	 * Applies one conditional lifecycle transition.
	 *
	 * The expected state is part of the UPDATE, so two workers processing an
	 * Accept and a Reject concurrently cannot both win. A duplicate or a
	 * response after Unfollow changes nothing and returns false.
	 */
	public function updateState(string $id, FollowState $expected, FollowState $state): bool;

	/**
	 * Deletes only if the edge belongs to the given local user.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $localUserId, string $actorId): bool;

	/**
	 * The actor ids one local user has an accepted follow for, newest first.
	 *
	 * This is the public following collection: a pending request is not yet a
	 * follow, and a rejected request must never appear.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array;

	/**
	 * One page of the public following collection, newest first.
	 *
	 * @return list<string>
	 */
	public function actorIdsPage(string $localUserId, int $limit, int $offset): array;

	/**
	 * The accepted remote edges one local user created, newest first.
	 *
	 * The profile following collection merges these with local-to-local
	 * edges and needs both the actor id and the follow time.
	 *
	 * @return list<FollowedActor>
	 */
	public function followedActors(string $localUserId): array;

	public function count(string $localUserId): int;

	/**
	 * The local users with an accepted follow of one remote actor, in stable
	 * order. Inbound content is accepted only for such an actor; this is the
	 * authorization lookup for that decision. Pending and rejected edges do
	 * not grant content access.
	 *
	 * @return list<string>
	 */
	public function localUserIdsForActor(string $actorId): array;
}
