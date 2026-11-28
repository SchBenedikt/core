<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * The canonical schema of the durable job queue.
 *
 * A host runs these statements from its migration step. The PDO queue and the
 * host integrations document these exact columns; keeping the definition here
 * is what keeps those descriptions from drifting.
 */
final class JobSchema
{
	public const TABLE = 'social_job';

	public const DUE_INDEX = 'social_job_due';

	public const DEAD_INDEX = 'social_job_dead';

	public const SUBJECT_INDEX = 'social_job_subject';

	private const ID_DEFINITION = 'VARCHAR(64) NOT NULL PRIMARY KEY';

	private const TYPE_DEFINITION = 'VARCHAR(64) NOT NULL';

	private const PAYLOAD_DEFINITION = 'TEXT NOT NULL';

	private const AVAILABLE_AT_DEFINITION = 'VARCHAR(20) NOT NULL';

	private const ATTEMPTS_DEFINITION = 'INTEGER NOT NULL';

	private const RESERVED_AT_DEFINITION = 'VARCHAR(20) NULL';

	private const DEAD_AT_DEFINITION = 'VARCHAR(20) NULL';

	private const SUBJECT_TYPE_DEFINITION = 'VARCHAR(32) NULL';

	private const SUBJECT_ID_DEFINITION = 'VARCHAR(255) NULL';

	private const LAST_ERROR_DEFINITION = 'VARCHAR(200) NULL';

	private const DUE_INDEX_COLUMNS = 'available_at, id';

	private const DEAD_INDEX_COLUMNS = 'dead_at';

	private const SUBJECT_INDEX_COLUMNS = 'subject_type, subject_id';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id ' . self::ID_DEFINITION . ','
			. ' type ' . self::TYPE_DEFINITION . ','
			. ' payload ' . self::PAYLOAD_DEFINITION . ','
			. ' available_at ' . self::AVAILABLE_AT_DEFINITION . ','
			. ' attempts ' . self::ATTEMPTS_DEFINITION . ','
			. ' reserved_at ' . self::RESERVED_AT_DEFINITION . ','
			. ' dead_at ' . self::DEAD_AT_DEFINITION . ','
			. ' subject_type ' . self::SUBJECT_TYPE_DEFINITION . ','
			. ' subject_id ' . self::SUBJECT_ID_DEFINITION . ','
			. ' last_error ' . self::LAST_ERROR_DEFINITION
			. ')',
			'CREATE INDEX ' . self::DUE_INDEX . ' ON ' . self::TABLE
			. ' (' . self::DUE_INDEX_COLUMNS . ')',
			'CREATE INDEX ' . self::DEAD_INDEX . ' ON ' . self::TABLE
				. ' (' . self::DEAD_INDEX_COLUMNS . ')',
			'CREATE INDEX ' . self::SUBJECT_INDEX . ' ON ' . self::TABLE
				. ' (' . self::SUBJECT_INDEX_COLUMNS . ')',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}

	/**
	 * Brings an existing `social_job` table forward without deleting rows.
	 *
	 * Older installations have no subject columns. They are nullable so the
	 * existing queue survives the upgrade; a legacy row has no envelope and is
	 * treated as unattributable rather than being guessed from its payload.
	 * The method is idempotent, so a retried migration is safe.
	 */
	public static function upgrade(Connection $connection): void
	{
		if (!SchemaUpgrade::tableExists($connection, self::TABLE)) {
			self::create($connection);

			return;
		}

		SchemaUpgrade::addColumnIfMissing(
			$connection,
			self::TABLE,
			'subject_type',
			self::SUBJECT_TYPE_DEFINITION,
		);
		SchemaUpgrade::addColumnIfMissing(
			$connection,
			self::TABLE,
			'subject_id',
			self::SUBJECT_ID_DEFINITION,
		);
		SchemaUpgrade::addColumnIfMissing(
			$connection,
			self::TABLE,
			'last_error',
			self::LAST_ERROR_DEFINITION,
		);
		SchemaUpgrade::createIndexIfMissing(
			$connection,
			self::TABLE,
			self::DUE_INDEX,
			self::DUE_INDEX_COLUMNS,
		);
		SchemaUpgrade::createIndexIfMissing(
			$connection,
			self::TABLE,
			self::DEAD_INDEX,
			self::DEAD_INDEX_COLUMNS,
		);
		SchemaUpgrade::createIndexIfMissing(
			$connection,
			self::TABLE,
			self::SUBJECT_INDEX,
			self::SUBJECT_INDEX_COLUMNS,
		);
	}
}
