<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Per-record hashtag rows for time-windowed trend counts.
 *
 * The composite primary key is the idempotency boundary: a reindex replaces
 * an entry's rows through {@see TrendRepository::record()}, and a duplicate
 * insert cannot inflate a count.
 */
final class TrendSchema
{
	public const TABLE = 'social_search_trend';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' entry_id VARCHAR(640) NOT NULL,'
			. ' hashtag VARCHAR(100) NOT NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (entry_id, hashtag)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_tag_time ON ' . self::TABLE . ' (hashtag, published_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
