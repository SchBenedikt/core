<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of private followed hashtags.
 *
 * The composite primary key is the idempotency boundary, so following the
 * same tag twice keeps the original timestamp. The timeline index serves the
 * newest-first page and the tag-ordered tie-break.
 */
final class FollowedHashtagSchema
{
	public const TABLE = 'social_followed_hashtag';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' hashtag VARCHAR(100) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (user_id, hashtag)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE
			. ' (user_id, created_at, hashtag)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
