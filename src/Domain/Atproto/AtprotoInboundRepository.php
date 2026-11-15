<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;

/**
 * Durable checkpoint, remote records and account state for inbound AT sync.
 *
 * Records and the checkpoint are written in the same transaction by the
 * importer, so a crash either commits both or repeats a page. The repository
 * itself stays a plain store; ordering and moderation live in the service.
 */
interface AtprotoInboundRepository
{
	public function checkpoint(string $source): ?AtprotoSyncCheckpoint;

	public function saveCheckpoint(AtprotoSyncCheckpoint $checkpoint): void;

	public function record(string $source, string $uri): ?AtprotoRemoteRecord;

	/**
	 * Inserts or replaces the latest state for `(source, uri)`.
	 */
	public function saveRecord(AtprotoRemoteRecord $record): void;

	/**
	 * Live records of one collection for an author, newest published first.
	 *
	 * The cursor is a `(published_at, uri)` tuple. Rows without a published
	 * timestamp are not part of a readable timeline and are excluded.
	 *
	 * @return list<AtprotoRemoteRecord>
	 */
	public function listRecords(
		string $source,
		string $did,
		string $collection,
		int $limit,
		?DateTimeImmutable $beforePublishedAt = null,
		?string $beforeUri = null,
	): array;

	/**
	 * Removes every record of a DID, including tombstones.
	 *
	 * Account revocation is authoritative: keeping a tombstone would let a
	 * later cached read reveal that the account existed.
	 *
	 * @return int rows removed
	 */
	public function deleteRecords(string $source, string $did): int;

	public function account(string $source, string $did): ?AtprotoRemoteAccount;

	public function accountByHandle(string $source, string $handle): ?AtprotoRemoteAccount;

	public function saveAccount(AtprotoRemoteAccount $account): void;
}
