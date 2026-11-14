<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Private conversation mutes keyed by a resolved thread root.
 */
final class ConversationMuteSchema
{
	public const TABLE = 'social_conversation_mute';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(80) NOT NULL PRIMARY KEY,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' conversation_id VARCHAR(512) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_pair ON ' . self::TABLE
			. ' (user_id, conversation_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
