<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Storage of the follow graph.
 *
 * A follower belongs to exactly one local user; the pair (local user, actor)
 * is unique. That is what makes a repeated Follow request an update instead of
 * a duplicate delivery.
 */
interface FollowerRepository
{
	public function insert(Follower $follower): void;

	/**
	 * Changes the inbox of an existing follower, keeping its id and time.
	 */
	public function updateInbox(Follower $follower): void;

	public function find(string $id): ?Follower;

	public function findByActor(string $localUserId, string $actorId): ?Follower;

	/**
	 * Deletes only if the follower belongs to the given local user.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $id, string $localUserId): bool;

	/**
	 * Deletes the edge for an actor, whatever its local id.
	 *
	 * An inbound Undo knows the actor and the local account, not the id this
	 * instance assigned to the follower.
	 *
	 * @return bool whether a row was removed
	 */
	public function removeByActor(string $localUserId, string $actorId): bool;

	/**
	 * Newest followers first, with the id as the tie-break.
	 *
	 * @return list<Follower>
	 */
	public function followers(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * The actor ids that follow one local user, newest first.
	 *
	 * This is the followers collection: all of them, not a page.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array;

	/**
	 * One page of the public followers collection, newest first.
	 *
	 * @return list<string>
	 */
	public function actorIdsPage(string $localUserId, int $limit, int $offset): array;

	/**
	 * The local accounts that follow the given local actor.
	 *
	 * Local-to-local follows are stored in this graph too, but they have no
	 * remote inbox to deliver to. The local actor id makes the direction
	 * unambiguous: local user B is in the list when actor A follows B.
	 *
	 * @return list<string> local user ids, in stable order
	 */
	public function localUserIdsFollowedBy(string $actorId): array;

	/**
	 * The local edges one local actor created, newest first.
	 *
	 * The profile following collection needs the target id and the follow
	 * time together, not just the stable target list above.
	 *
	 * @return list<Follower>
	 */
	public function localFollowsByActor(string $actorId): array;

	/**
	 * The number of local accounts the given local actor follows.
	 */
	public function localFollowingCount(string $actorId): int;

	/**
	 * The distinct remote inboxes to deliver one local user's posts to.
	 *
	 * Local-to-local edges are excluded: they are already on this instance
	 * and must not be sent into the ActivityPub delivery queue.
	 *
	 * @return list<string>
	 */
	public function inboxesFor(string $localUserId): array;

	public function count(string $localUserId): int;
}
