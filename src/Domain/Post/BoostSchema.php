<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of local boosts.
 *
 * The pair index is the idempotency boundary: one account can boost a post
 * once. The post index supports counts, the user index supports a profile or
 * home timeline, and the global index supports paging local boosts.
 */
final class BoostSchema
{
	public const TABLE = 'social_boost';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' UNIQUE (user_id, post_id)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_post ON ' . self::TABLE . ' (post_id)',
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE . ' (user_id, created_at)',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE . ' (created_at, id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
