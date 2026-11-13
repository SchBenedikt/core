<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of private per-user account mutes.
 *
 * The composite primary key is the idempotency boundary and supports an
 * upsert. The user index serves the newest-first mute list and its cursor;
 * the actor index supports cleanup and diagnostics.
 */
final class MuteSchema
{
	public const TABLE = 'social_mute';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(512) NOT NULL,'
			. ' notifications SMALLINT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' expires_at VARCHAR(20) NULL,'
			. ' PRIMARY KEY (user_id, actor_id)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE
			. ' (user_id, created_at, actor_id)',
			'CREATE INDEX ' . self::TABLE . '_actor ON ' . self::TABLE . ' (actor_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
