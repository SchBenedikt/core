<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Story;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Story tables. No post row is created for a story, and every receipt table
 * is keyed by the story plus viewer/author so a retry cannot duplicate it.
 */
final class StorySchema
{
	public const TABLE = 'social_story';

	public const VIEW_TABLE = 'social_story_view';

	public const REACTION_TABLE = 'social_story_reaction';

	public const REPLY_TABLE = 'social_story_reply';

	public const TOMBSTONE_TABLE = 'social_story_tombstone';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' owner_id VARCHAR(512) NOT NULL,'
			. ' is_local SMALLINT NOT NULL,'
			. ' object_id VARCHAR(512) NOT NULL UNIQUE,'
			. ' caption TEXT NOT NULL,'
			. ' attachment_id VARCHAR(64) NULL,'
			. ' remote_media_url VARCHAR(2048) NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' expires_at VARCHAR(20) NOT NULL,'
			. ' deleted_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_owner ON ' . self::TABLE
			. ' (owner_id, created_at)',
			'CREATE INDEX ' . self::TABLE . '_expiry ON ' . self::TABLE
			. ' (expires_at, deleted_at)',
			'CREATE TABLE ' . self::VIEW_TABLE . ' ('
			. ' story_id VARCHAR(512) NOT NULL,'
			. ' viewer_id VARCHAR(512) NOT NULL,'
			. ' viewed_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (story_id, viewer_id)'
			. ')',
			'CREATE TABLE ' . self::REACTION_TABLE . ' ('
			. ' story_id VARCHAR(512) NOT NULL,'
			. ' user_id VARCHAR(512) NOT NULL,'
			. ' emoji VARCHAR(64) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (story_id, user_id, emoji)'
			. ')',
			'CREATE TABLE ' . self::REPLY_TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' story_id VARCHAR(512) NOT NULL,'
			. ' author_id VARCHAR(512) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::REPLY_TABLE . '_story ON ' . self::REPLY_TABLE
			. ' (story_id, created_at)',
			'CREATE TABLE ' . self::TOMBSTONE_TABLE . ' ('
			. ' object_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' owner_id VARCHAR(512) NOT NULL,'
			. ' deleted_at VARCHAR(20) NOT NULL'
			. ')',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
