<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use InvalidArgumentException;

/**
 * SQL implementation of the native PDS store.
 *
 * Every multi-row write runs in one database transaction so a crash cannot
 * leave a new record without its commit, or a commit without its blocks.
 */
final class PdoPdsRepository implements PdsRepository
{
	public function __construct(private readonly Connection $database)
	{
	}

	public function identityForUser(string $localUserId): ?PdsIdentity
	{
		return $this->identityWhere('local_user_id', $localUserId);
	}

	public function identityByDid(string $did): ?PdsIdentity
	{
		return $this->identityWhere('did', $did);
	}

	public function identityByHandle(string $handle): ?PdsIdentity
	{
		return $this->identityWhere('handle', $handle);
	}

	public function keys(string $did): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::KEY_TABLE . ' WHERE did = ? ORDER BY created_at ASC, id ASC',
			[$did],
		);

		return array_map(fn (array $row): PdsKey => $this->hydrateKey($row), $rows);
	}

	public function key(string $did, string $keyId): ?PdsKey
	{
		$row = $this->database->fetchOne(
			'SELECT * FROM ' . PdsSchema::KEY_TABLE . ' WHERE did = ? AND id = ?',
			[$did, $keyId],
		);

		return $row === null ? null : $this->hydrateKey($row);
	}

	public function createIdentity(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		PdsPlcOperation $operation,
		PdsCommit $commit,
		array $blocks,
	): void {
		$this->database->transaction(function (Connection $database) use (
			$identity,
			$signingKey,
			$rotationKey,
			$operation,
			$commit,
			$blocks,
		): void {
			$this->insertIdentity($database, $identity);
			$this->insertKey($database, $signingKey);
			$this->insertKey($database, $rotationKey);
			$this->insertCommit($database, $commit);
			$this->insertBlocks($database, $identity->did, $blocks);
			$this->insertOperation($database, $operation);
		});
	}

	public function rotateIdentity(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		array $retiredKeyIds,
		PdsPlcOperation $operation,
	): void {
		$this->database->transaction(function (Connection $database) use (
			$identity,
			$signingKey,
			$rotationKey,
			$retiredKeyIds,
			$operation,
		): void {
			$database->execute(
				'UPDATE ' . PdsSchema::IDENTITY_TABLE
				. ' SET signing_key_id = ?, rotation_key_id = ?, updated_at = ? WHERE did = ?',
				[$identity->signingKeyId, $identity->rotationKeyId, $identity->updatedAt, $identity->did],
			);
			$this->insertKey($database, $signingKey);
			$this->insertKey($database, $rotationKey);

			foreach ($retiredKeyIds as $keyId) {
				$database->execute(
					'UPDATE ' . PdsSchema::KEY_TABLE
					. ' SET active = 0, retired_at = ? WHERE did = ? AND id = ?',
					[$identity->updatedAt, $identity->did, $keyId],
				);
			}

			$this->insertOperation($database, $operation);
		});
	}

	public function records(string $did): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::RECORD_TABLE . ' WHERE did = ? ORDER BY collection ASC, rkey ASC',
			[$did],
		);

		$records = [];
		foreach ($rows as $row) {
			$record = $this->hydrateRecord($row);
			$records[$record->collection . '/' . $record->rkey] = $record;
		}

		return $records;
	}

	public function record(string $did, string $collection, string $rkey): ?PdsRecord
	{
		$row = $this->database->fetchOne(
			'SELECT * FROM ' . PdsSchema::RECORD_TABLE . ' WHERE did = ? AND collection = ? AND rkey = ?',
			[$did, $collection, $rkey],
		);

		return $row === null ? null : $this->hydrateRecord($row);
	}

	public function applyCommit(
		PdsIdentity $identity,
		PdsCommit $commit,
		array $blocks,
		array $puts,
		array $deletes,
		?PdsEvent $event = null,
	): ?PdsEvent {
		return $this->database->transaction(function (Connection $database) use (
			$identity,
			$commit,
			$blocks,
			$puts,
			$deletes,
			$event,
		): ?PdsEvent {
			$database->execute(
				'UPDATE ' . PdsSchema::IDENTITY_TABLE
				. ' SET head_cid = ?, head_rev = ?, seq = ?, updated_at = ? WHERE did = ?',
				[
					$identity->head?->toString(),
					$identity->headRev,
					$identity->seq,
					$identity->updatedAt,
					$identity->did,
				],
			);

			$this->insertCommit($database, $commit);
			$this->insertBlocks($database, $identity->did, $blocks);

			foreach ($deletes as $delete) {
				$database->execute(
					'DELETE FROM ' . PdsSchema::RECORD_TABLE . ' WHERE did = ? AND collection = ? AND rkey = ?',
					[$identity->did, $delete[0], $delete[1]],
				);
			}

			foreach ($puts as $put) {
				$database->execute(
					'DELETE FROM ' . PdsSchema::RECORD_TABLE . ' WHERE did = ? AND collection = ? AND rkey = ?',
					[$put->did, $put->collection, $put->rkey],
				);
				$database->execute(
					'INSERT INTO ' . PdsSchema::RECORD_TABLE
					. ' (did, collection, rkey, cid, record_cbor, created_at, updated_at)'
					. ' VALUES (?, ?, ?, ?, ?, ?, ?)',
					[
						$put->did,
						$put->collection,
						$put->rkey,
						$put->cid->toString(),
						base64_encode($put->cbor),
						$put->createdAt,
						$put->updatedAt,
					],
				);
			}

			return $event === null ? null : $this->insertEvent($database, $event);
		});
	}

	public function appendEvent(PdsEvent $event): PdsEvent
	{
		return $this->database->transaction(
			fn (Connection $database): PdsEvent => $this->insertEvent($database, $event),
		);
	}

	public function eventsAfter(int $cursor, int $limit): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::EVENT_TABLE . ' WHERE seq > ? ORDER BY seq ASC LIMIT ?',
			[$cursor, max(1, $limit)],
		);

		return array_map(fn (array $row): PdsEvent => $this->hydrateEvent($row), $rows);
	}

	public function latestSeq(): int
	{
		$value = $this->database->fetchValue(
			'SELECT COALESCE(MAX(seq), 0) FROM ' . PdsSchema::EVENT_TABLE,
		);

		return (int) ($value ?? 0);
	}

	public function identities(): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::IDENTITY_TABLE . ' ORDER BY created_at ASC, did ASC',
		);

		return array_map(fn (array $row): PdsIdentity => $this->hydrateIdentity($row), $rows);
	}

	public function blobs(string $did): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::BLOB_TABLE . ' WHERE did = ? ORDER BY cid ASC',
			[$did],
		);

		$blobs = [];
		foreach ($rows as $row) {
			$blob = $this->hydrateBlob($row);
			$blobs[$blob->cid] = $blob;
		}

		return $blobs;
	}

	public function blob(string $did, string $cid): ?PdsBlob
	{
		$row = $this->database->fetchOne(
			'SELECT * FROM ' . PdsSchema::BLOB_TABLE . ' WHERE did = ? AND cid = ?',
			[$did, $cid],
		);

		return $row === null ? null : $this->hydrateBlob($row);
	}

	public function putBlob(PdsBlob $blob): void
	{
		$this->database->execute(
			'INSERT INTO ' . PdsSchema::BLOB_TABLE
			. ' (did, cid, mime_type, size, created_at) VALUES (?, ?, ?, ?, ?)',
			[$blob->did, $blob->cid, $blob->mimeType, $blob->size, $blob->createdAt],
		);
	}

	public function totalBlobBytes(string $did): int
	{
		$value = $this->database->fetchValue(
			'SELECT COALESCE(SUM(size), 0) FROM ' . PdsSchema::BLOB_TABLE . ' WHERE did = ?',
			[$did],
		);

		return (int) ($value ?? 0);
	}

	public function commits(string $did): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::COMMIT_TABLE . ' WHERE did = ? ORDER BY seq ASC',
			[$did],
		);

		return array_map(fn (array $row): PdsCommit => $this->hydrateCommit($row), $rows);
	}

	public function blocks(string $did): array
	{
		$rows = $this->database->fetchAll(
			'SELECT cid, block_cbor FROM ' . PdsSchema::BLOCK_TABLE . ' WHERE did = ?',
			[$did],
		);

		$blocks = [];
		foreach ($rows as $row) {
			$blocks[(string) $row['cid']] = base64_decode((string) $row['block_cbor'], true) ?: '';
		}

		return $blocks;
	}

	public function plcOperations(string $did): array
	{
		$rows = $this->database->fetchAll(
			'SELECT * FROM ' . PdsSchema::PLC_TABLE . ' WHERE did = ? ORDER BY seq ASC',
			[$did],
		);

		return array_map(static fn (array $row): PdsPlcOperation => new PdsPlcOperation(
			(string) $row['did'],
			Cid::fromString((string) $row['cid']),
			(int) $row['seq'],
			base64_decode((string) $row['operation_cbor'], true) ?: '',
			(string) $row['created_at'],
		), $rows);
	}

	private function identityWhere(string $column, string $value): ?PdsIdentity
	{
		if (!in_array($column, ['local_user_id', 'did', 'handle'], true)) {
			throw new InvalidArgumentException('Unsupported PDS identity lookup column.');
		}

		$row = $this->database->fetchOne(
			'SELECT * FROM ' . PdsSchema::IDENTITY_TABLE . ' WHERE ' . $column . ' = ?',
			[$value],
		);

		return $row === null ? null : $this->hydrateIdentity($row);
	}

	private function insertIdentity(Connection $database, PdsIdentity $identity): void
	{
		$database->execute(
			'INSERT INTO ' . PdsSchema::IDENTITY_TABLE
			. ' (local_user_id, did, handle, signing_key_id, rotation_key_id, head_cid, head_rev, seq, created_at, updated_at)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$identity->localUserId,
				$identity->did,
				$identity->handle,
				$identity->signingKeyId,
				$identity->rotationKeyId,
				$identity->head?->toString(),
				$identity->headRev,
				$identity->seq,
				$identity->createdAt,
				$identity->updatedAt,
			],
		);
	}

	private function insertKey(Connection $database, PdsKey $key): void
	{
		$database->execute(
			'INSERT INTO ' . PdsSchema::KEY_TABLE
			. ' (id, local_user_id, did, role, private_key_sealed, public_key, public_key_multibase, active, created_at, retired_at)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$key->id,
				$key->localUserId,
				$key->did,
				$key->role,
				$key->sealedPrivateKey,
				$key->publicKeyPem,
				$key->publicKeyMultibase,
				$key->active,
				$key->createdAt,
				$key->retiredAt,
			],
		);
	}

	private function insertCommit(Connection $database, PdsCommit $commit): void
	{
		$database->execute(
			'INSERT INTO ' . PdsSchema::COMMIT_TABLE
			. ' (did, cid, seq, rev, data_cid, prev_cid, signing_key_id, commit_cbor, created_at)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$commit->did,
				$commit->cid->toString(),
				$commit->seq,
				$commit->rev,
				$commit->dataCid->toString(),
				$commit->previousCid?->toString(),
				$commit->signingKeyId,
				base64_encode($commit->bytes),
				$commit->createdAt,
			],
		);
	}

	/**
	 * @param array<string, string> $blocks
	 */
	private function insertBlocks(Connection $database, string $did, array $blocks): void
	{
		foreach ($blocks as $cid => $bytes) {
			$exists = $database->fetchValue(
				'SELECT 1 FROM ' . PdsSchema::BLOCK_TABLE . ' WHERE did = ? AND cid = ?',
				[$did, $cid],
			);
			if ($exists === null) {
				$database->execute(
					'INSERT INTO ' . PdsSchema::BLOCK_TABLE . ' (did, cid, block_cbor) VALUES (?, ?, ?)',
					[$did, $cid, base64_encode($bytes)],
				);
			}
		}
	}

	private function insertEvent(Connection $database, PdsEvent $event): PdsEvent
	{
		$seq = $this->nextSequence($database);
		$stored = $event->withSeq($seq);
		$database->execute(
			'INSERT INTO ' . PdsSchema::EVENT_TABLE
			. ' (seq, did, commit_cid, rev, since_rev, ops_json, blocks_car, blobs_json, created_at)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$stored->seq,
				$stored->did,
				$stored->commitCid,
				$stored->rev,
				$stored->sinceRev,
				json_encode($stored->ops, JSON_THROW_ON_ERROR),
				base64_encode($stored->blocks),
				json_encode($stored->blobs, JSON_THROW_ON_ERROR),
				$stored->createdAt,
			],
		);

		return $stored;
	}

	private function nextSequence(Connection $database): int
	{
		$value = $database->fetchValue(
			'SELECT value FROM ' . PdsSchema::SEQUENCE_TABLE . ' WHERE name = ?',
			['firehose'],
		);
		if ($value === null) {
			$database->execute(
				'INSERT INTO ' . PdsSchema::SEQUENCE_TABLE . ' (name, value) VALUES (?, ?)',
				['firehose', 1],
			);

			return 1;
		}

		$database->execute(
			'UPDATE ' . PdsSchema::SEQUENCE_TABLE . ' SET value = value + 1 WHERE name = ?',
			['firehose'],
		);

		return (int) $database->fetchValue(
			'SELECT value FROM ' . PdsSchema::SEQUENCE_TABLE . ' WHERE name = ?',
			['firehose'],
		);
	}

	private function hydrateEvent(array $row): PdsEvent
	{
		$ops = json_decode((string) $row['ops_json'], true);
		$blobs = json_decode((string) $row['blobs_json'], true);

		return new PdsEvent(
			(int) $row['seq'],
			(string) $row['did'],
			(string) $row['commit_cid'],
			(string) $row['rev'],
			(string) $row['since_rev'],
			is_array($ops) ? $ops : [],
			base64_decode((string) $row['blocks_car'], true) ?: '',
			is_array($blobs) ? array_values(array_map('strval', $blobs)) : [],
			(string) $row['created_at'],
		);
	}

	private function insertOperation(Connection $database, PdsPlcOperation $operation): void
	{
		$database->execute(
			'INSERT INTO ' . PdsSchema::PLC_TABLE
			. ' (did, cid, seq, operation_cbor, created_at) VALUES (?, ?, ?, ?, ?)',
			[
				$operation->did,
				$operation->cid->toString(),
				$operation->seq,
				base64_encode($operation->bytes),
				$operation->createdAt,
			],
		);
	}

	private function hydrateIdentity(array $row): PdsIdentity
	{
		return new PdsIdentity(
			(string) $row['local_user_id'],
			(string) $row['did'],
			(string) $row['handle'],
			(string) $row['signing_key_id'],
			(string) $row['rotation_key_id'],
			$row['head_cid'] === null ? null : Cid::fromString((string) $row['head_cid']),
			$row['head_rev'] === null ? null : (string) $row['head_rev'],
			(int) $row['seq'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
		);
	}

	private function hydrateKey(array $row): PdsKey
	{
		return new PdsKey(
			(string) $row['id'],
			(string) $row['local_user_id'],
			(string) $row['did'],
			(string) $row['role'],
			(string) $row['private_key_sealed'],
			(string) $row['public_key'],
			(string) $row['public_key_multibase'],
			(int) $row['active'] === 1,
			(string) $row['created_at'],
			$row['retired_at'] === null ? null : (string) $row['retired_at'],
		);
	}

	private function hydrateRecord(array $row): PdsRecord
	{
		return new PdsRecord(
			(string) $row['did'],
			(string) $row['collection'],
			(string) $row['rkey'],
			Cid::fromString((string) $row['cid']),
			base64_decode((string) $row['record_cbor'], true) ?: '',
			(string) $row['created_at'],
			(string) $row['updated_at'],
		);
	}

	private function hydrateBlob(array $row): PdsBlob
	{
		return new PdsBlob(
			(string) $row['did'],
			(string) $row['cid'],
			(string) $row['mime_type'],
			(int) $row['size'],
			(string) $row['created_at'],
		);
	}

	private function hydrateCommit(array $row): PdsCommit
	{
		return new PdsCommit(
			(string) $row['did'],
			Cid::fromString((string) $row['cid']),
			(int) $row['seq'],
			(string) $row['rev'],
			Cid::fromString((string) $row['data_cid']),
			$row['prev_cid'] === null ? null : Cid::fromString((string) $row['prev_cid']),
			(string) $row['signing_key_id'],
			base64_decode((string) $row['commit_cbor'], true) ?: '',
			(string) $row['created_at'],
		);
	}
}
