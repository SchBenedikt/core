<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoRemoteRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoSyncCheckpoint;
use DateTimeImmutable;
use RuntimeException;

/**
 * Delegates all reads/writes except the checkpoint, which always fails.
 */
final class CheckpointFailingAtprotoRepository implements AtprotoInboundRepository
{
	public function __construct(
		private readonly AtprotoInboundRepository $inner,
	) {
	}

	public function checkpoint(string $source): ?AtprotoSyncCheckpoint
	{
		return $this->inner->checkpoint($source);
	}

	public function saveCheckpoint(AtprotoSyncCheckpoint $checkpoint): void
	{
		throw new RuntimeException('checkpoint write failed');
	}

	public function record(string $source, string $uri): ?AtprotoRemoteRecord
	{
		return $this->inner->record($source, $uri);
	}

	public function saveRecord(AtprotoRemoteRecord $record): void
	{
		$this->inner->saveRecord($record);
	}

	public function listRecords(
		string $source,
		string $did,
		string $collection,
		int $limit,
		?DateTimeImmutable $beforePublishedAt = null,
		?string $beforeUri = null,
	): array {
		return $this->inner->listRecords($source, $did, $collection, $limit, $beforePublishedAt, $beforeUri);
	}

	public function deleteRecords(string $source, string $did): int
	{
		return $this->inner->deleteRecords($source, $did);
	}

	public function account(string $source, string $did): ?AtprotoRemoteAccount
	{
		return $this->inner->account($source, $did);
	}

	public function accountByHandle(string $source, string $handle): ?AtprotoRemoteAccount
	{
		return $this->inner->accountByHandle($source, $handle);
	}

	public function saveAccount(AtprotoRemoteAccount $account): void
	{
		$this->inner->saveAccount($account);
	}
}
