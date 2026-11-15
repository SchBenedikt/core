<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use DateTimeImmutable;

use function array_filter;
use function array_slice;
use function array_values;
use function strcmp;
use function usort;

/**
 * In-memory inbound store, mirroring the SQL primary keys and ordering.
 */
final class InMemoryAtprotoInboundRepository implements AtprotoInboundRepository
{
	/** @var array<string, AtprotoSyncCheckpoint> */
	private array $checkpoints = [];

	/** @var array<string, AtprotoRemoteRecord> */
	private array $records = [];

	/** @var array<string, AtprotoRemoteAccount> */
	private array $accounts = [];

	public function checkpoint(string $source): ?AtprotoSyncCheckpoint
	{
		return $this->checkpoints[$source] ?? null;
	}

	public function saveCheckpoint(AtprotoSyncCheckpoint $checkpoint): void
	{
		$this->checkpoints[$checkpoint->source] = $checkpoint;
	}

	public function record(string $source, string $uri): ?AtprotoRemoteRecord
	{
		return $this->records[$this->key($source, $uri)] ?? null;
	}

	public function saveRecord(AtprotoRemoteRecord $record): void
	{
		$this->records[$this->key($record->source, $record->uri)] = $record;
	}

	public function listRecords(
		string $source,
		string $did,
		string $collection,
		int $limit,
		?DateTimeImmutable $beforePublishedAt = null,
		?string $beforeUri = null,
	): array {
		$records = array_values(array_filter(
			$this->records,
			static fn (AtprotoRemoteRecord $record): bool => $record->source === $source
				&& $record->did === $did
				&& $record->collection === $collection
				&& !$record->deleted
				&& $record->publishedAt !== null,
		));

		usort($records, static function (AtprotoRemoteRecord $a, AtprotoRemoteRecord $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;
			if ($byTime !== 0) {
				return $byTime;
			}

			return strcmp($b->uri, $a->uri);
		});

		if ($beforePublishedAt !== null && $beforeUri !== null) {
			$records = array_values(array_filter(
				$records,
				static fn (AtprotoRemoteRecord $record): bool => $record->publishedAt < $beforePublishedAt
					|| ($record->publishedAt == $beforePublishedAt && strcmp($record->uri, $beforeUri) < 0),
			));
		}

		return array_slice($records, 0, $limit);
	}

	public function deleteRecords(string $source, string $did): int
	{
		$removed = 0;
		foreach ($this->records as $key => $record) {
			if ($record->source === $source && $record->did === $did) {
				unset($this->records[$key]);
				++$removed;
			}
		}

		return $removed;
	}

	public function account(string $source, string $did): ?AtprotoRemoteAccount
	{
		return $this->accounts[$this->key($source, $did)] ?? null;
	}

	public function accountByHandle(string $source, string $handle): ?AtprotoRemoteAccount
	{
		foreach ($this->accounts as $account) {
			if ($account->source === $source && $account->handle === $handle) {
				return $account;
			}
		}

		return null;
	}

	public function saveAccount(AtprotoRemoteAccount $account): void
	{
		$this->accounts[$this->key($account->source, $account->did)] = $account;
	}

	private function key(string $source, string $value): string
	{
		return $source . "\0" . $value;
	}
}
