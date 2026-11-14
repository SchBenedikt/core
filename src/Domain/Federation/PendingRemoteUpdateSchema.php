<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the early-update buffer.
 *
 * The object id is the primary key: one object has exactly one current
 * pending version, and a second Update replaces the first instead of growing
 * the table. `received_at` is what bounds the store; it is written by the
 * core, never by the peer.
 */
final class PendingRemoteUpdateSchema
{
	public const TABLE = 'social_remote_pending_update';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' object_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' activity_id VARCHAR(512) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' updated_at VARCHAR(20) NOT NULL,'
			. ' received_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_received ON ' . self::TABLE . ' (received_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
