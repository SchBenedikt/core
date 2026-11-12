<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the follower table.
 *
 * Column lengths are index-friendly on purpose: the unique index over
 * (local_user_id, actor_id) has to fit MySQL's key limit with utf8mb4, which
 * is why the actor URL is capped at 255 characters here and rejected by the
 * service before it reaches the database. `shared_inbox_url` is nullable so existing
 * follower rows keep working and fall back to their individual inbox.
 * `is_local` distinguishes a local-to-local edge from a remote inbox; local
 * edges are never used for content delivery. Existing rows default to remote.
 */
final class FollowerSchema
{
	public const TABLE = 'social_follower';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' inbox_url VARCHAR(512) NOT NULL,'
			. ' shared_inbox_url VARCHAR(512) NULL,'
			. ' followed_at VARCHAR(20) NOT NULL,'
			. ' is_local SMALLINT NOT NULL DEFAULT 0'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_identity ON ' . self::TABLE
			. ' (local_user_id, actor_id)',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE
			. ' (local_user_id, followed_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
