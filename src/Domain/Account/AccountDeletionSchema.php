<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * The canonical schema of the account deletion tombstone.
 *
 * The row outlives the account's own rows and blobs: it is the durable
 * evidence that a deletion was requested and completed, and the write gate
 * reads it before a mutation touches anything. `deleted_counts` and
 * `blob_failures` are JSON because the report is diagnostic data, not a
 * second copy of the account tables.
 */
final class AccountDeletionSchema
{
	public const TABLE = 'social_account_deletion';

	public const STATE_INDEX = 'social_account_deletion_state';

	private const STATE_INDEX_COLUMNS = 'state, updated_at';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [self::tableStatement(), self::stateIndexStatement()];
	}

	/**
	 * Creates the tombstone on a fresh installation.
	 */
	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}

	/**
	 * Adds the tombstone to an installation that predates account deletion.
	 *
	 * Safe to call more than once and safe to retry after a partial deploy:
	 * the table and index are only created when the catalogue does not have
	 * them yet. A host runs this from the same migration step as the other
	 * schema classes; it does not need a second DDL copy.
	 */
	public static function upgrade(Connection $connection): void
	{
		SchemaUpgrade::createTableIfMissing($connection, self::TABLE, self::tableStatement());
		SchemaUpgrade::createIndexIfMissing(
			$connection,
			self::TABLE,
			self::STATE_INDEX,
			self::STATE_INDEX_COLUMNS,
		);
	}

	private static function tableStatement(): string
	{
		return 'CREATE TABLE ' . self::TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL PRIMARY KEY,'
			. ' state VARCHAR(16) NOT NULL,'
			. ' requested_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL,'
			. ' completed_at VARCHAR(20) NULL,'
			. ' deleted_counts TEXT NOT NULL,'
			. ' blobs_deleted INTEGER NOT NULL,'
			. ' blob_failures TEXT NOT NULL,'
			. ' attempts INTEGER NOT NULL,'
			. ' lease_until VARCHAR(20) NULL'
			. ')';
	}

	private static function stateIndexStatement(): string
	{
		return 'CREATE INDEX ' . self::STATE_INDEX . ' ON ' . self::TABLE
			. ' (' . self::STATE_INDEX_COLUMNS . ')';
	}
}
