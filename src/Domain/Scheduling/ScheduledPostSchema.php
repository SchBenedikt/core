<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of scheduled posts.
 *
 * `post_id` is unique because it is reserved at scheduling time and becomes
 * the post's primary key: a retry writes the same id, never a second post.
 * The two id lists are JSON because they are input for a future post, not the
 * canonical relationship tables.
 */
final class ScheduledPostSchema
{
	public const TABLE = 'social_scheduled_post';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' author_id VARCHAR(255) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' language VARCHAR(35) NULL,'
			. ' visibility VARCHAR(16) NOT NULL,'
			. ' attachment_ids TEXT NOT NULL,'
			. ' recipient_ids TEXT NOT NULL,'
			. ' in_reply_to_id VARCHAR(64) NULL,'
			. ' scheduled_at VARCHAR(20) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' state VARCHAR(16) NOT NULL,'
			. ' lease_until VARCHAR(20) NULL,'
			. ' published_at VARCHAR(20) NULL,'
			. ' failure_reason VARCHAR(500) NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_post ON ' . self::TABLE . ' (post_id)',
			'CREATE INDEX ' . self::TABLE . '_author ON ' . self::TABLE . ' (author_id, created_at, id)',
			'CREATE INDEX ' . self::TABLE . '_due ON ' . self::TABLE . ' (state, scheduled_at, id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
