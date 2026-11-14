<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of per-reader keyword filters.
 *
 * One row is one rule. `contexts` and `keywords` are JSON because they are the
 * rule's own value, not a shared relationship: the unique index on `id` plus a
 * single-row write is what keeps a rule consistent.
 */
final class FilterSchema
{
	public const TABLE = 'social_filter';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' name VARCHAR(100) NOT NULL,'
			. ' action VARCHAR(8) NOT NULL,'
			. ' contexts TEXT NOT NULL,'
			. ' keywords TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' expires_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE . ' (user_id, created_at, id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
