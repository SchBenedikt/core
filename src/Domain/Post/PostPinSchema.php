<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * A separate pin table keeps pinning out of the post row and lets the
 * featured collection be rebuilt from a small ordered set.
 */
final class PostPinSchema
{
	public const TABLE = 'social_post_pin';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' owner_id VARCHAR(255) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' pinned_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (owner_id, post_id),'
			. ' UNIQUE (owner_id, position)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_post ON ' . self::TABLE . ' (post_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
