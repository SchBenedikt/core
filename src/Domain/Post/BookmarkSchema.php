<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of private per-user post bookmarks.
 *
 * The composite primary key is the idempotency boundary. The post index
 * supports cleanup and diagnostics; the user index serves the bookmark
 * timeline in its stored order.
 */
final class BookmarkSchema
{
	public const TABLE = 'social_bookmark';

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
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE . ' (user_id, created_at, post_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
