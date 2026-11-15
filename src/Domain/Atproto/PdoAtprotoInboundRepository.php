<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Inbound AT Protocol sync state in SQL.
 *
 * The checkpoint, records and accounts use the same keys as the in-memory
 * adapter. Record writes are update-then-insert rather than vendor-specific
 * upsert syntax; the primary key still refuses a concurrent duplicate.
 */
final class PdoAtprotoInboundRepository implements AtprotoInboundRepository
{
	private const CHECKPOINT_COLUMNS = 'source, cursor, last_sequence, last_event_at,'
		. ' last_attempt_at, last_error, stale, reconcile_required';

	private const RECORD_COLUMNS = 'source, uri, did, collection, rkey, cid, rev, sequence,'
		. ' record_json, deleted, published_at, received_at, updated_at';

	private const ACCOUNT_COLUMNS = 'source, did, handle, status, active, updated_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function checkpoint(string $source): ?AtprotoSyncCheckpoint
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::CHECKPOINT_COLUMNS . ' FROM ' . AtprotoInboundSchema::CHECKPOINT_TABLE
			. ' WHERE source = :source',
			['source' => $source],
		);

		return $row === null ? null : $this->hydrateCheckpoint($row);
	}

	public function saveCheckpoint(AtprotoSyncCheckpoint $checkpoint): void
	{
		$parameters = [
			'source' => $checkpoint->source,
			'cursor' => $checkpoint->cursor,
			'last_sequence' => $checkpoint->lastSequence,
			'last_event_at' => $checkpoint->lastEventAt === null ? null : Time::format($checkpoint->lastEventAt),
			'last_attempt_at' => Time::format($checkpoint->lastAttemptAt),
			'last_error' => $checkpoint->lastError,
			'stale' => $checkpoint->stale ? 1 : 0,
			'reconcile_required' => $checkpoint->reconcileRequired ? 1 : 0,
		];

		if ($this->checkpoint($checkpoint->source) !== null) {
			$this->db->execute(
				'UPDATE ' . AtprotoInboundSchema::CHECKPOINT_TABLE . ' SET'
				. ' cursor = :cursor, last_sequence = :last_sequence, last_event_at = :last_event_at,'
				. ' last_attempt_at = :last_attempt_at, last_error = :last_error,'
				. ' stale = :stale, reconcile_required = :reconcile_required'
				. ' WHERE source = :source',
				$parameters,
			);

			return;
		}

		try {
			$this->db->execute(
				'INSERT INTO ' . AtprotoInboundSchema::CHECKPOINT_TABLE
				. ' (' . self::CHECKPOINT_COLUMNS . ') VALUES ('
				. ' :source, :cursor, :last_sequence, :last_event_at, :last_attempt_at,'
				. ' :last_error, :stale, :reconcile_required)',
				$parameters,
			);
		} catch (Throwable $exception) {
			throw new DatabaseException('The AT Protocol sync checkpoint could not be stored.', 0, $exception);
		}
	}

	public function record(string $source, string $uri): ?AtprotoRemoteRecord
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::RECORD_COLUMNS . ' FROM ' . AtprotoInboundSchema::RECORD_TABLE
			. ' WHERE source = :source AND uri = :uri',
			['source' => $source, 'uri' => $uri],
		);

		return $row === null ? null : $this->hydrateRecord($row);
	}

	public function saveRecord(AtprotoRemoteRecord $record): void
	{
		try {
			$parameters = [
				'source' => $record->source,
				'uri' => $record->uri,
				'did' => $record->did,
				'collection' => $record->collection,
				'rkey' => $record->rkey,
				'cid' => $record->cid,
				'rev' => $record->rev,
				'sequence' => $record->sequence,
				'record_json' => json_encode($record->record, JSON_THROW_ON_ERROR),
				'deleted' => $record->deleted ? 1 : 0,
				'published_at' => $record->publishedAt === null ? null : Time::format($record->publishedAt),
				'received_at' => Time::format($record->receivedAt),
				'updated_at' => Time::format($record->updatedAt),
			];
		} catch (JsonException $exception) {
			throw new DatabaseException('The AT Protocol record body could not be encoded.', 0, $exception);
		}

		if ($this->record($record->source, $record->uri) !== null) {
			$this->db->execute(
				'UPDATE ' . AtprotoInboundSchema::RECORD_TABLE . ' SET'
				. ' did = :did, collection = :collection, rkey = :rkey, cid = :cid, rev = :rev,'
				. ' sequence = :sequence, record_json = :record_json, deleted = :deleted,'
				. ' published_at = :published_at, received_at = :received_at, updated_at = :updated_at'
				. ' WHERE source = :source AND uri = :uri',
				$parameters,
			);

			return;
		}

		try {
			$this->db->execute(
				'INSERT INTO ' . AtprotoInboundSchema::RECORD_TABLE
				. ' (' . self::RECORD_COLUMNS . ') VALUES ('
				. ' :source, :uri, :did, :collection, :rkey, :cid, :rev, :sequence,'
				. ' :record_json, :deleted, :published_at, :received_at, :updated_at)',
				$parameters,
			);
		} catch (Throwable $exception) {
			throw new DatabaseException('The AT Protocol remote record could not be stored.', 0, $exception);
		}
	}

	public function listRecords(
		string $source,
		string $did,
		string $collection,
		int $limit,
		?DateTimeImmutable $beforePublishedAt = null,
		?string $beforeUri = null,
	): array {
		$sql = 'SELECT ' . self::RECORD_COLUMNS . ' FROM ' . AtprotoInboundSchema::RECORD_TABLE
			. ' WHERE source = :source AND did = :did AND collection = :collection'
			. ' AND deleted = 0 AND published_at IS NOT NULL';
		$parameters = [
			'source' => $source,
			'did' => $did,
			'collection' => $collection,
		];

		if ($beforePublishedAt !== null && $beforeUri !== null) {
			$sql .= ' AND (published_at < :before_published_at'
				. ' OR (published_at = :before_published_at AND uri < :before_uri))';
			$parameters['before_published_at'] = Time::format($beforePublishedAt);
			$parameters['before_uri'] = $beforeUri;
		}

		$sql .= ' ORDER BY published_at DESC, uri DESC LIMIT ' . $limit;

		$rows = $this->db->fetchAll($sql, $parameters);

		return array_map(fn (array $row): AtprotoRemoteRecord => $this->hydrateRecord($row), $rows);
	}

	public function deleteRecords(string $source, string $did): int
	{
		return $this->db->execute(
			'DELETE FROM ' . AtprotoInboundSchema::RECORD_TABLE
			. ' WHERE source = :source AND did = :did',
			['source' => $source, 'did' => $did],
		);
	}

	public function account(string $source, string $did): ?AtprotoRemoteAccount
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::ACCOUNT_COLUMNS . ' FROM ' . AtprotoInboundSchema::ACCOUNT_TABLE
			. ' WHERE source = :source AND did = :did',
			['source' => $source, 'did' => $did],
		);

		return $row === null ? null : $this->hydrateAccount($row);
	}

	public function accountByHandle(string $source, string $handle): ?AtprotoRemoteAccount
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::ACCOUNT_COLUMNS . ' FROM ' . AtprotoInboundSchema::ACCOUNT_TABLE
			. ' WHERE source = :source AND handle = :handle'
			. ' ORDER BY updated_at DESC LIMIT 1',
			['source' => $source, 'handle' => $handle],
		);

		return $row === null ? null : $this->hydrateAccount($row);
	}

	public function saveAccount(AtprotoRemoteAccount $account): void
	{
		$parameters = [
			'source' => $account->source,
			'did' => $account->did,
			'handle' => $account->handle,
			'status' => $account->status->value,
			'active' => $account->active ? 1 : 0,
			'updated_at' => Time::format($account->updatedAt),
		];

		if ($this->account($account->source, $account->did) !== null) {
			$this->db->execute(
				'UPDATE ' . AtprotoInboundSchema::ACCOUNT_TABLE . ' SET'
				. ' handle = :handle, status = :status, active = :active, updated_at = :updated_at'
				. ' WHERE source = :source AND did = :did',
				$parameters,
			);

			return;
		}

		try {
			$this->db->execute(
				'INSERT INTO ' . AtprotoInboundSchema::ACCOUNT_TABLE
				. ' (' . self::ACCOUNT_COLUMNS . ') VALUES ('
				. ' :source, :did, :handle, :status, :active, :updated_at)',
				$parameters,
			);
		} catch (Throwable $exception) {
			throw new DatabaseException('The AT Protocol remote account could not be stored.', 0, $exception);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrateCheckpoint(array $row): AtprotoSyncCheckpoint
	{
		$lastEventAt = $row['last_event_at'];

		return new AtprotoSyncCheckpoint(
			source: (string) $row['source'],
			cursor: $row['cursor'] === null ? null : (string) $row['cursor'],
			lastSequence: $row['last_sequence'] === null ? null : (int) $row['last_sequence'],
			lastEventAt: $lastEventAt === null ? null : Time::parse((string) $lastEventAt),
			lastAttemptAt: Time::parse((string) $row['last_attempt_at']),
			lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
			stale: (int) $row['stale'] === 1,
			reconcileRequired: (int) $row['reconcile_required'] === 1,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrateRecord(array $row): AtprotoRemoteRecord
	{
		try {
			$record = json_decode((string) $row['record_json'], true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new DatabaseException('A stored AT Protocol record body is unreadable.', 0, $exception);
		}

		if (!is_array($record)) {
			throw new DatabaseException('A stored AT Protocol record body is not an object.');
		}

		return new AtprotoRemoteRecord(
			source: (string) $row['source'],
			did: (string) $row['did'],
			collection: (string) $row['collection'],
			rkey: (string) $row['rkey'],
			uri: (string) $row['uri'],
			cid: $row['cid'] === null ? null : (string) $row['cid'],
			rev: $row['rev'] === null ? null : (string) $row['rev'],
			sequence: $row['sequence'] === null ? null : (int) $row['sequence'],
			record: $record,
			deleted: (int) $row['deleted'] === 1,
			publishedAt: $row['published_at'] === null ? null : Time::parse((string) $row['published_at']),
			receivedAt: Time::parse((string) $row['received_at']),
			updatedAt: Time::parse((string) $row['updated_at']),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrateAccount(array $row): AtprotoRemoteAccount
	{
		$status = AtprotoAccountStatus::tryFrom((string) $row['status']);
		if ($status === null) {
			throw new DatabaseException('A stored AT Protocol account has an unknown status.');
		}

		return new AtprotoRemoteAccount(
			source: (string) $row['source'],
			did: (string) $row['did'],
			handle: is_string($row['handle']) ? (string) $row['handle'] : null,
			status: $status,
			active: (int) $row['active'] === 1,
			updatedAt: Time::parse((string) $row['updated_at']),
		);
	}
}
