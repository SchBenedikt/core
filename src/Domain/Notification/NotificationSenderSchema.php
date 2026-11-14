<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Held sender requests and explicit sender decisions.
 *
 * The unique (recipient, actor) index is the idempotency boundary; the id is
 * deterministic so an in-memory and a SQL store answer the same request id.
 */
final class NotificationSenderSchema
{
	public const TABLE = 'social_notification_sender';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(80) NOT NULL PRIMARY KEY,'
			. ' recipient_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(512) NOT NULL,'
			. ' status VARCHAR(16) NOT NULL,'
			. ' category VARCHAR(16) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' reviewed_at VARCHAR(20) NULL,'
			. ' note TEXT NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_pair ON ' . self::TABLE
			. ' (recipient_id, actor_id)',
			'CREATE INDEX ' . self::TABLE . '_queue ON ' . self::TABLE
			. ' (recipient_id, status, created_at, id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
