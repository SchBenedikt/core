<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the outgoing follow table.
 *
 * Separate from `social_follower` on purpose: a follower is an inbound edge,
 * a followed actor is an outbound edge. Both store an inbox, but for opposite
 * directions: one receives posts, the other receives Follow and Undo.
 *
 * `state` defaults to accepted so rows created before the lifecycle existed
 * stay valid. New outgoing follows are inserted as pending and become
 * accepted or rejected only through a signed response. `follow_activity_id`
 * is the exact id an Accept/Reject may embed; the unique index makes a
 * response match at most one row.
 */
final class FollowingSchema
{
	public const TABLE = 'social_following';

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
			. ' followed_at VARCHAR(20) NOT NULL,'
			. " state VARCHAR(16) NOT NULL DEFAULT 'accepted',"
			. ' follow_activity_id VARCHAR(512) NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_identity ON ' . self::TABLE
			. ' (local_user_id, actor_id)',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_follow_activity ON ' . self::TABLE
			. ' (follow_activity_id)',
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
