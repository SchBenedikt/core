<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Collection;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Collections are a separate table pair rather than post columns: one post
 * can belong to several ordered albums without changing the post timeline.
 */
final class MediaCollectionSchema
{
	public const TABLE = 'social_media_collection';

	public const ITEM_TABLE = 'social_media_collection_item';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' owner_id VARCHAR(255) NOT NULL,'
			. ' title VARCHAR(100) NOT NULL,'
			. ' description TEXT NOT NULL,'
			. ' audience VARCHAR(16) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_owner ON ' . self::TABLE . ' (owner_id, created_at)',
			'CREATE TABLE ' . self::ITEM_TABLE . ' ('
			. ' collection_id VARCHAR(64) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' added_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (collection_id, post_id),'
			. ' UNIQUE (collection_id, position)'
			. ')',
			'CREATE INDEX ' . self::ITEM_TABLE . '_post ON ' . self::ITEM_TABLE . ' (post_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
