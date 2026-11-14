<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the attachment metadata table.
 *
 * The bytes are not in this table. `storage_key` and `thumbnail_key` are
 * opaque BlobStore paths, so moving to another blob backend does not change
 * the database contract.
 */
final class AttachmentSchema
{
	public const TABLE = 'social_attachment';

	/**
	 * Columns an existing installation has to add. The defaults keep every
	 * stored image readable as an image without a re-import.
	 *
	 * @return list<string>
	 */
	public static function upgradeStatements(): array
	{
		return [
			"ALTER TABLE " . self::TABLE . " ADD COLUMN media_kind VARCHAR(16) NOT NULL DEFAULT 'image'",
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN duration_seconds INTEGER NOT NULL DEFAULT 0',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN animated SMALLINT NOT NULL DEFAULT 0',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN focus_x REAL NULL',
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN focus_y REAL NULL',
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
			. ' owner_id VARCHAR(255) NOT NULL,'
			. ' storage_key VARCHAR(512) NOT NULL,'
			. ' thumbnail_key VARCHAR(512) NOT NULL,'
			. ' mime_type VARCHAR(64) NOT NULL,'
			. ' width INTEGER NOT NULL,'
			. ' height INTEGER NOT NULL,'
			. ' byte_size INTEGER NOT NULL,'
			. ' alt_text VARCHAR(1000) NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' source_url VARCHAR(512) NULL,'
			. " media_kind VARCHAR(16) NOT NULL DEFAULT 'image',"
			. ' duration_seconds INTEGER NOT NULL DEFAULT 0,'
			. ' animated SMALLINT NOT NULL DEFAULT 0,'
			. ' focus_x REAL NULL,'
			. ' focus_y REAL NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_owner ON ' . self::TABLE . ' (owner_id, created_at)',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_source ON ' . self::TABLE . ' (source_url)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
