<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical discovery index table.
 *
 * `hashtags` and `aliases` are JSON arrays because both are small, always
 * read as a whole, and would otherwise need two more join tables for no
 * query benefit. The read path still filters visibility and kind in SQL.
 */
final class SearchIndexSchema
{
	public const TABLE = 'social_search_index';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(640) NOT NULL PRIMARY KEY,'
			. ' kind VARCHAR(16) NOT NULL,'
			. ' source VARCHAR(16) NOT NULL,'
			. ' author_id VARCHAR(512) NULL,'
			. ' content TEXT NOT NULL,'
			. ' hashtags TEXT NOT NULL,'
			. ' aliases TEXT NOT NULL,'
			. ' visibility VARCHAR(16) NOT NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' cached_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_kind_time ON ' . self::TABLE . ' (kind, published_at)',
			'CREATE INDEX ' . self::TABLE . '_author_time ON ' . self::TABLE . ' (author_id, published_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
