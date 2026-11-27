<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the post table.
 *
 * The standalone host runs these statements from its migration step; the
 * Nextcloud host has to create the same columns through its own schema API.
 * Keeping the definition here is what makes the two databases
 * interchangeable — the id and column names never drift.
 */
final class PostSchema
{
	public const TABLE = 'social_post';

	public const ATTACHMENT_TABLE = 'social_post_attachment';

	public const RECIPIENT_TABLE = 'social_post_recipient';

	public const HASHTAG_TABLE = 'social_post_hashtag';

	/**
	 * Superseded versions of a post, written by an edit.
	 *
	 * The live row keeps the current version; this table is append-only and
	 * keyed by (post_id, revision), so an edit history cannot be rewritten.
	 */
	public const REVISION_TABLE = 'social_post_revision';

	/**
	 * Columns an existing installation has to add to keep up with this schema.
	 *
	 * Migrations are host-owned: a fresh install runs {@see statements()}, an
	 * existing one runs these. `CREATE TABLE IF NOT EXISTS` keeps the revision
	 * table additive for both.
	 *
	 * @return list<string>
	 */
	public static function upgradeStatements(): array
	{
		return [
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN revision INTEGER NOT NULL DEFAULT 1',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN edited_at VARCHAR(20) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN quoted_object_id VARCHAR(1024) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN quote_state VARCHAR(16) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN quote_authorization_id VARCHAR(1024) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN quote_request_id VARCHAR(1024) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN archived_at VARCHAR(20) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN language VARCHAR(35) NULL',
			'ALTER TABLE ' . self::REVISION_TABLE . ' ADD COLUMN language VARCHAR(35) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN in_reply_to_remote_object_id VARCHAR(512) NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN in_reply_to_remote_author VARCHAR(512) NULL',
		];
	}

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' author_id VARCHAR(255) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' visibility VARCHAR(16) NOT NULL,'
			. ' in_reply_to_id VARCHAR(64) NULL,'
			. ' in_reply_to_remote_object_id VARCHAR(512) NULL,'
			. ' in_reply_to_remote_author VARCHAR(512) NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' edited_at VARCHAR(20) NULL,'
			. ' revision INTEGER NOT NULL DEFAULT 1,'
			. ' deleted_at VARCHAR(20) NULL,'
			. ' quoted_object_id VARCHAR(1024) NULL,'
			. ' quote_state VARCHAR(16) NULL,'
			. ' quote_authorization_id VARCHAR(1024) NULL,'
			. ' quote_request_id VARCHAR(1024) NULL,'
			. ' archived_at VARCHAR(20) NULL,'
			. ' language VARCHAR(35) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE . ' (visibility, published_at)',
			'CREATE INDEX ' . self::TABLE . '_author ON ' . self::TABLE . ' (author_id, published_at)',
			'CREATE INDEX ' . self::TABLE . '_reply ON ' . self::TABLE . ' (in_reply_to_id, published_at)',
			'CREATE INDEX ' . self::TABLE . '_remote_reply ON ' . self::TABLE . ' (in_reply_to_remote_object_id, published_at)',
			'CREATE INDEX ' . self::TABLE . '_quote ON ' . self::TABLE . ' (quoted_object_id, published_at)',
			'CREATE TABLE ' . self::ATTACHMENT_TABLE . ' ('
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' attachment_id VARCHAR(64) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' PRIMARY KEY (post_id, attachment_id)'
			. ')',
			'CREATE INDEX ' . self::ATTACHMENT_TABLE . '_post ON ' . self::ATTACHMENT_TABLE . ' (post_id, position)',
			'CREATE TABLE ' . self::RECIPIENT_TABLE . ' ('
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' recipient_id VARCHAR(255) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' PRIMARY KEY (post_id, recipient_id)'
			. ')',
			'CREATE INDEX ' . self::RECIPIENT_TABLE . '_post ON ' . self::RECIPIENT_TABLE . ' (post_id, position)',
			'CREATE INDEX ' . self::RECIPIENT_TABLE . '_recipient ON ' . self::RECIPIENT_TABLE . ' (recipient_id, post_id)',
			'CREATE TABLE ' . self::HASHTAG_TABLE . ' ('
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' hashtag VARCHAR(100) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' PRIMARY KEY (post_id, hashtag)'
			. ')',
			'CREATE INDEX ' . self::HASHTAG_TABLE . '_tag ON ' . self::HASHTAG_TABLE . ' (hashtag, post_id)',
			'CREATE TABLE ' . self::REVISION_TABLE . ' ('
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' revision INTEGER NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' visibility VARCHAR(16) NOT NULL,'
			. ' language VARCHAR(35) NULL,'
			. ' attachment_ids TEXT NOT NULL,'
			. ' edited_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (post_id, revision)'
			. ')',
			'CREATE INDEX ' . self::REVISION_TABLE . '_post ON ' . self::REVISION_TABLE . ' (post_id, revision)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
