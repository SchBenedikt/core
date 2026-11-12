<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of per-user post favourites.
 *
 * The composite primary key is the idempotency boundary. The post index
 * supports counts for a status projection; the user index supports a future
 * favourites timeline without a second table.
 */
final class FavouriteSchema
{
	public const TABLE = 'social_favourite';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (user_id, post_id)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_post ON ' . self::TABLE . ' (post_id)',
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE . ' (user_id, created_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
