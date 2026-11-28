<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the remote-object tombstone store.
 *
 * The primary key is the actor/object pair: one author can only tombstone an
 * identity once, and the same object id under a different actor is a different
 * row, which is what keeps a forged Delete from suppressing somebody else's
 * content. `activity_id` records which Delete established the tombstone for
 * audit; `deleted_at` is the core's clock, never the peer's.
 */
final class RemoteObjectTombstoneSchema
{
	public const TABLE = 'social_remote_object_tombstone';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' object_id VARCHAR(512) NOT NULL,'
			. ' activity_id VARCHAR(512) NOT NULL,'
			. ' deleted_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (actor_id, object_id)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_deleted ON ' . self::TABLE . ' (deleted_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
