<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The durable state of ActivityPub account moves.
 *
 * The event id is the primary key, so a retried Move cannot create a second
 * migration. The processed lists are JSON text because they are append-only
 * progress, not a queryable relation; the bounded batch never needs to join
 * them. The origin/direction index serves the actor document's `movedTo`.
 */
final class AccountMoveSchema
{
	public const TABLE = 'social_account_move';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' event_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' origin_actor_id VARCHAR(512) NOT NULL,'
			. ' target_actor_id VARCHAR(512) NOT NULL,'
			. ' target_inbox_url VARCHAR(512) NOT NULL,'
			. ' direction VARCHAR(16) NOT NULL,'
			. ' state VARCHAR(16) NOT NULL,'
			. ' processed_outgoing_ids TEXT NULL,'
			. ' processed_follower_ids TEXT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_origin ON ' . self::TABLE
			. ' (origin_actor_id, direction)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
