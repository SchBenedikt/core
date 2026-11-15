<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Durable native-PDS state.
 *
 * Binary values are stored base64 encoded in TEXT columns so the same schema
 * works on SQLite and the relational databases a host may use. Every table is
 * scoped by the local account or DID; there is no global record namespace.
 */
final class PdsSchema
{
	public const IDENTITY_TABLE = 'social_pds_identity';

	public const KEY_TABLE = 'social_pds_key';

	public const RECORD_TABLE = 'social_pds_record';

	public const COMMIT_TABLE = 'social_pds_commit';

	public const BLOCK_TABLE = 'social_pds_block';

	public const PLC_TABLE = 'social_pds_plc_operation';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::IDENTITY_TABLE . ' ('
			. ' local_user_id VARCHAR(255) NOT NULL PRIMARY KEY,'
			. ' did VARCHAR(255) NOT NULL,'
			. ' handle VARCHAR(253) NOT NULL,'
			. ' signing_key_id VARCHAR(64) NOT NULL,'
			. ' rotation_key_id VARCHAR(64) NOT NULL,'
			. ' head_cid VARCHAR(255) NULL,'
			. ' head_rev VARCHAR(64) NULL,'
			. ' seq INTEGER NOT NULL DEFAULT 0,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::IDENTITY_TABLE . '_did ON ' . self::IDENTITY_TABLE . ' (did)',
			'CREATE UNIQUE INDEX ' . self::IDENTITY_TABLE . '_handle ON ' . self::IDENTITY_TABLE . ' (handle)',
			'CREATE TABLE ' . self::KEY_TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' did VARCHAR(255) NOT NULL,'
			. ' role VARCHAR(16) NOT NULL,'
			. ' private_key_sealed TEXT NOT NULL,'
			. ' public_key TEXT NOT NULL,'
			. ' public_key_multibase VARCHAR(512) NOT NULL,'
			. ' active SMALLINT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' retired_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::KEY_TABLE . '_did ON ' . self::KEY_TABLE . ' (did, active)',
			'CREATE TABLE ' . self::RECORD_TABLE . ' ('
			. ' did VARCHAR(255) NOT NULL,'
			. ' collection VARCHAR(128) NOT NULL,'
			. ' rkey VARCHAR(512) NOT NULL,'
			. ' cid VARCHAR(255) NOT NULL,'
			. ' record_cbor TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (did, collection, rkey)'
			. ')',
			'CREATE INDEX ' . self::RECORD_TABLE . '_collection ON ' . self::RECORD_TABLE
			. ' (did, collection, rkey)',
			'CREATE TABLE ' . self::COMMIT_TABLE . ' ('
			. ' did VARCHAR(255) NOT NULL,'
			. ' cid VARCHAR(255) NOT NULL,'
			. ' seq INTEGER NOT NULL,'
			. ' rev VARCHAR(64) NOT NULL,'
			. ' data_cid VARCHAR(255) NOT NULL,'
			. ' prev_cid VARCHAR(255) NULL,'
			. ' signing_key_id VARCHAR(64) NOT NULL,'
			. ' commit_cbor TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (did, cid)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::COMMIT_TABLE . '_seq ON ' . self::COMMIT_TABLE . ' (did, seq)',
			'CREATE TABLE ' . self::BLOCK_TABLE . ' ('
			. ' did VARCHAR(255) NOT NULL,'
			. ' cid VARCHAR(255) NOT NULL,'
			. ' block_cbor TEXT NOT NULL,'
			. ' PRIMARY KEY (did, cid)'
			. ')',
			'CREATE TABLE ' . self::PLC_TABLE . ' ('
			. ' did VARCHAR(255) NOT NULL,'
			. ' cid VARCHAR(255) NOT NULL,'
			. ' seq INTEGER NOT NULL,'
			. ' operation_cbor TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (did, cid)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::PLC_TABLE . '_seq ON ' . self::PLC_TABLE . ' (did, seq)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
