<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the remote thread context cache.
 *
 * `object_id` is unique because one object may be reached as a parent and as a
 * reply in different requests. `fetched_at` lets a host expire context
 * independently of delivered remote posts.
 */
final class RemoteThreadSchema
{
	public const TABLE = 'social_remote_thread_post';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' object_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' author_id VARCHAR(255) NOT NULL,'
			. ' content TEXT NOT NULL,'
			. ' content_warning VARCHAR(255) NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' in_reply_to_id VARCHAR(512) NULL,'
			. ' fetched_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_parent ON ' . self::TABLE
			. ' (in_reply_to_id, published_at)',
			'CREATE INDEX ' . self::TABLE . '_fetched ON ' . self::TABLE
			. ' (fetched_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
