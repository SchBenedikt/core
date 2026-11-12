<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use AlohaSocial\Core\Platform\Db\Connection;

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

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' type VARCHAR(64) NOT NULL,'
			. ' payload TEXT NOT NULL,'
			. ' available_at VARCHAR(20) NOT NULL,'
			. ' attempts INTEGER NOT NULL,'
			. ' reserved_at VARCHAR(20) NULL,'
			. ' dead_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_due ON ' . self::TABLE
			. ' (available_at, id)',
			'CREATE INDEX ' . self::TABLE . '_dead ON ' . self::TABLE
			. ' (dead_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
