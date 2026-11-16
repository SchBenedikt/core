<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

/**
 * The SQL boundary for an account-wide archive and erasure.
 *
 * Account data is spread over the feature repositories by design: a post, a
 * follow, a story or a PDS record belongs to its own aggregate. Exporting or
 * deleting an account therefore needs one read/write pass over those tables,
 * not a second copy of them. The adapter returns raw rows so the archive keeps
 * every column the host actually stores.
 */
interface AccountDataRepository
{
	/**
	 * Every row and blob path attributable to one local account.
	 *
	 * The actor id is the public ActivityPub identity derived from the local
	 * user id. It is used for tables that store an actor URL instead of the
	 * local id (quote grants and account moves).
	 */
	public function snapshot(string $userId, string $actorId): AccountDataSnapshot;

	/**
	 * Removes every attributable row and returns the affected counts plus the
	 * blob paths that belonged to the account.
	 */
	public function erase(string $userId, string $actorId): AccountDataErasure;
}
