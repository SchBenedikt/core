<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Storage of private per-user account mutes.
 *
 * `save` is an upsert: muting an already muted account updates its settings
 * and start time, which makes the API idempotent without a second row.
 * Expired rows stay stored until a host cleanup, but every read accepts the
 * current time and hides them.
 */
interface MuteRepository
{
	public function save(Mute $mute): void;

	/**
	 * Deletes only the owner's mute. Deleting an absent pair is a no-op.
	 *
	 * @return bool whether a row was removed
	 */
	public function remove(string $userId, string $actorId): bool;

	/**
	 * The stored row, including an expired one; the service applies expiry.
	 */
	public function find(string $userId, string $actorId): ?Mute;

	/**
	 * Newest active mutes, with the actor id as the tie-break.
	 *
	 * The before/after pair is an exclusive cursor on (created_at, actor_id).
	 * A non-null $now excludes rows whose `expires_at` has been reached.
	 *
	 * @return list<Mute>
	 */
	public function forUser(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeActorId = null,
		?DateTimeImmutable $after = null,
		?string $afterActorId = null,
		?DateTimeImmutable $now = null,
	): array;

	/**
	 * Active target ids for the home timeline, newest first.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $userId, ?DateTimeImmutable $now = null): array;

	/**
	 * Active targets whose notifications are muted, newest first.
	 *
	 * @return list<string>
	 */
	public function notificationActorIds(string $userId, ?DateTimeImmutable $now = null): array;
}
