<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the remote-post store.
 *
 * `activity_id` is the unique idempotence key. `object_id` is not unique:
 * different actors can boost the same object, and a post and an Announce can
 * legitimately refer to the same Note.
 */
final class RemotePostSchema
{
	public const TABLE = 'social_remote_post';

	public const ATTACHMENT_TABLE = 'social_remote_post_attachment';

	/**
	 * Columns an existing installation has to add.
	 *
	 * `updated_at` records the last applied `Update`; a null value is a post
	 * that has only ever been seen in its created form.
	 *
	 * @return list<string>
	 */
	public static function upgradeStatements(): array
	{
		return [
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN updated_at VARCHAR(20) NULL',
			"ALTER TABLE " . self::TABLE . " ADD COLUMN object_type VARCHAR(16) NOT NULL DEFAULT 'note'",
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN object_metadata TEXT NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN quoted_object_id VARCHAR(512) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN in_reply_to_id VARCHAR(512) NULL',
		];
	}

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' activity_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' object_id VARCHAR(512) NOT NULL,'
			. ' object_author_id VARCHAR(255) NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' kind VARCHAR(16) NOT NULL,'
			. " object_type VARCHAR(16) NOT NULL DEFAULT 'note',"
			. ' object_metadata TEXT NULL,'
			. ' quoted_object_id VARCHAR(512) NULL,'
			. ' in_reply_to_id VARCHAR(512) NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NULL,'
			. ' deleted_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE
			. ' (actor_id, published_at)',
			'CREATE INDEX ' . self::TABLE . '_object ON ' . self::TABLE
			. ' (object_id, kind)',
			'CREATE TABLE ' . self::ATTACHMENT_TABLE . ' ('
			. ' activity_id VARCHAR(512) NOT NULL,'
			. ' attachment_id VARCHAR(64) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' PRIMARY KEY (activity_id, attachment_id)'
			. ')',
			'CREATE INDEX ' . self::ATTACHMENT_TABLE . '_activity ON ' . self::ATTACHMENT_TABLE
			. ' (activity_id, position)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
