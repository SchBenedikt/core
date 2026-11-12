<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Canonical schema of local account notifications.
 *
 * The id is the event-derived primary key and therefore the idempotency
 * boundary. The timeline index matches the recipient page and cursor; the
 * unread index also serves the read-filtered list and count.
 * `read_at` is nullable so existing rows are unread; dismiss and clear set it
 * without deleting the historical event.
 */
final class NotificationSchema
{
	public const TABLE = 'social_notification';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' recipient_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' type VARCHAR(32) NOT NULL,'
			. ' post_id VARCHAR(64) NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' read_at VARCHAR(20) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_timeline ON ' . self::TABLE
			. ' (recipient_id, created_at, id)',
			'CREATE INDEX ' . self::TABLE . '_unread ON ' . self::TABLE
			. ' (recipient_id, read_at, created_at, id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
