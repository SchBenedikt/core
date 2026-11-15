<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Durable storage for inbound AT Protocol relay/Jetstream/AppView sync.
 *
 * The checkpoint is one row per source. Records are keyed by source and URI,
 * so the same URI from two sources stays distinct; a delete is a tombstone.
 * Account rows are separate because they describe identity availability, not
 * a repository record.
 */
final class AtprotoInboundSchema
{
	public const CHECKPOINT_TABLE = 'social_atproto_sync_checkpoint';

	public const RECORD_TABLE = 'social_atproto_remote_record';

	public const ACCOUNT_TABLE = 'social_atproto_remote_account';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::CHECKPOINT_TABLE . ' ('
			. ' source VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' cursor VARCHAR(2048) NULL,'
			. ' last_sequence BIGINT NULL,'
			. ' last_event_at VARCHAR(20) NULL,'
			. ' last_attempt_at VARCHAR(20) NOT NULL,'
			. ' last_error VARCHAR(500) NULL,'
			. ' stale SMALLINT NOT NULL DEFAULT 0,'
			. ' reconcile_required SMALLINT NOT NULL DEFAULT 0'
			. ')',
			'CREATE TABLE ' . self::RECORD_TABLE . ' ('
			. ' source VARCHAR(64) NOT NULL,'
			. ' uri VARCHAR(512) NOT NULL,'
			. ' did VARCHAR(512) NOT NULL,'
			. ' collection VARCHAR(128) NOT NULL,'
			. ' rkey VARCHAR(512) NOT NULL,'
			. ' cid VARCHAR(256) NULL,'
			. ' rev VARCHAR(64) NULL,'
			. ' sequence BIGINT NULL,'
			. ' record_json TEXT NOT NULL,'
			. ' deleted SMALLINT NOT NULL DEFAULT 0,'
			. ' published_at VARCHAR(20) NULL,'
			. ' received_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (source, uri)'
			. ')',
			'CREATE INDEX ' . self::RECORD_TABLE . '_timeline ON ' . self::RECORD_TABLE
			. ' (source, did, collection, deleted, published_at)',
			'CREATE INDEX ' . self::RECORD_TABLE . '_sequence ON ' . self::RECORD_TABLE
			. ' (source, sequence)',
			'CREATE TABLE ' . self::ACCOUNT_TABLE . ' ('
			. ' source VARCHAR(64) NOT NULL,'
			. ' did VARCHAR(512) NOT NULL,'
			. ' handle VARCHAR(253) NULL,'
			. ' status VARCHAR(16) NOT NULL,'
			. ' active SMALLINT NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (source, did)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::ACCOUNT_TABLE . '_handle ON ' . self::ACCOUNT_TABLE
			. ' (source, handle)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
