<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the per-user block table.
 *
 * The unique index over (local_user_id, actor_id) makes a repeated block an
 * update/read instead of a duplicate. Column lengths match the follower
 * tables so the same index limits apply on MySQL with utf8mb4.
 */
final class BlockSchema
{
	public const TABLE = 'social_block';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' blocked_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_identity ON ' . self::TABLE
			. ' (local_user_id, actor_id)',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE
			. ' (local_user_id, blocked_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
