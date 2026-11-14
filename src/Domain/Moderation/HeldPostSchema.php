<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Durable queue of validated posts waiting for review.
 *
 * The draft is JSON: it is input for a future PostService call, not a second
 * post table. `post_id` is filled only after approval created the real row.
 */
final class HeldPostSchema
{
	public const TABLE = 'social_held_post';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' author_id VARCHAR(255) NOT NULL,'
			. ' draft TEXT NOT NULL,'
			. ' reason VARCHAR(200) NOT NULL,'
			. ' status VARCHAR(16) NOT NULL,'
			. ' post_id VARCHAR(64) NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' reviewed_by VARCHAR(255) NULL,'
			. ' reviewed_at VARCHAR(20) NULL,'
			. ' review_note TEXT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_queue ON ' . self::TABLE . ' (status, created_at, id)',
			'CREATE INDEX ' . self::TABLE . '_author ON ' . self::TABLE . ' (author_id, created_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
