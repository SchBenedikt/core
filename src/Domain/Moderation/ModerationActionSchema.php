<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;

/**
 * Durable actor/domain policy actions.
 *
 * There is no unique index because a target may be actioned, reversed and
 * actioned again; the service checks for an active row before inserting.
 */
final class ModerationActionSchema
{
	public const TABLE = 'social_moderation_action';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' kind VARCHAR(32) NOT NULL,'
			. ' target VARCHAR(512) NOT NULL,'
			. ' report_id VARCHAR(64) NULL,'
			. ' moderator_id VARCHAR(255) NOT NULL,'
			. ' note TEXT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' reversed_at VARCHAR(20) NULL,'
			. ' reversed_by VARCHAR(255) NULL,'
			. ' reversal_note TEXT NULL,'
			. ' public_comment VARCHAR(500) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_active ON ' . self::TABLE . ' (kind, target, reversed_at)',
			'CREATE INDEX ' . self::TABLE . '_target ON ' . self::TABLE . ' (target, created_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}

	/**
	 * Older installations lack `public_comment`. The method is idempotent,
	 * so a retried migration is safe.
	 */
	public static function upgrade(Connection $connection): void
	{
		if (!SchemaUpgrade::tableExists($connection, self::TABLE)) {
			self::create($connection);

			return;
		}

		SchemaUpgrade::addColumnIfMissing(
			$connection,
			self::TABLE,
			'public_comment',
			'VARCHAR(500) NULL',
		);
	}
}
