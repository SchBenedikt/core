<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the durable activity replay log.
 *
 * The primary key is the guard: an insert of an already-seen activity fails,
 * so two processes cannot both claim it. `seen_at` makes old ids expirable.
 */
final class ActivityLogSchema
{
	public const TABLE = 'social_activity_log';

	/**
	 * Columns an existing installation has to add to keep up with this schema.
	 *
	 * @return list<string>
	 */
	public static function upgradeStatements(): array
	{
		return [
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN digest VARCHAR(64) NULL',
		];
	}

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' activity_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' seen_at VARCHAR(20) NOT NULL,'
			. ' digest VARCHAR(64) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_seen ON ' . self::TABLE . ' (seen_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
