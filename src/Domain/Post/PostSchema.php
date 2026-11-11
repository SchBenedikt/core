<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the post table.
 *
 * The standalone host runs these statements from its migration step; the
 * Nextcloud host has to create the same columns through its own schema API.
 * Keeping the definition here is what makes the two databases
 * interchangeable — the id and column names never drift.
 */
final class PostSchema
{
	public const TABLE = 'social_post';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' author_id VARCHAR(255) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' visibility VARCHAR(16) NOT NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' deleted_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE . ' (visibility, published_at)',
			'CREATE INDEX ' . self::TABLE . '_author ON ' . self::TABLE . ' (author_id, published_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
