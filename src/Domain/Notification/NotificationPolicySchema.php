<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * One JSON policy per recipient.
 *
 * The category map is small and always read as a whole, so JSON is simpler
 * than six action rows and stays portable across the supported databases.
 */
final class NotificationPolicySchema
{
	public const TABLE = 'social_notification_policy';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL PRIMARY KEY,'
			. ' actions TEXT NOT NULL,'
			. ' new_account_days INTEGER NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
