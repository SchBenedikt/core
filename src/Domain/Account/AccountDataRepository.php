<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

use DateTimeImmutable;

/**
 * The SQL boundary for an account-wide archive, erasure and deletion state.
 *
 * Account data is spread over the feature repositories by design: a post, a
 * follow, a story or a PDS record belongs to its own aggregate. Exporting or
 * deleting an account therefore needs one read/write pass over those tables,
 * not a second copy of them. The adapter returns raw rows so the archive keeps
 * every column the host actually stores.
 */
interface AccountDataRepository extends AccountWriteGuard
{
	/**
	 * The remote routes needed to announce this account's removal. The read
	 * happens after the durable deletion freeze but before its graph rows are
	 * erased.
	 */
	public function federationCleanup(string $userId): AccountFederationCleanup;

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
	public function erase(string $userId, string $actorId, ?DateTimeImmutable $deletedAt = null): AccountDataErasure;

	/**
	 * The persisted deletion row, or null when the account never asked.
	 */
	public function deletion(string $userId): ?AccountDeletion;

	/**
	 * Creates the durable `frozen` row before any row or blob is removed.
	 */
	public function beginDeletion(string $userId, DateTimeImmutable $now): AccountDeletion;

	/**
	 * Claims the one worker that may finish this deletion. A stale `erasing`
	 * lease can be taken over after a crash.
	 */
	public function claimDeletion(string $userId, DateTimeImmutable $now): bool;

	/**
	 * Returns a failed attempt to `frozen` so a retry does not wait for the
	 * lease to expire.
	 */
	public function releaseDeletion(string $userId, DateTimeImmutable $now): void;

	/**
	 * Stores the permanent result. The caller's report is the stored evidence
	 * of what was removed.
	 */
	public function finishDeletion(
		string $userId,
		array $counts,
		int $blobsDeleted,
		array $blobFailures,
		DateTimeImmutable $now,
	): AccountDeletion;
}
