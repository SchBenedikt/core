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
	 * Newest followers first, with the id as the tie-break.
	 *
	 * @return list<Follower>
	 */
	public function followers(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * The distinct inboxes to deliver one local user's posts to.
	 *
	 * @return list<string>
	 */
	public function inboxesFor(string $localUserId): array;

	public function count(string $localUserId): int;
}
