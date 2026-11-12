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
			. ' created_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_owner ON ' . self::TABLE . ' (owner_id, created_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
