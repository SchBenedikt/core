<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Conversation;

use AlohaSocial\Core\Platform\Db\Connection;

/** Schema for private, per-account direct-message read and dismissal state. */
final class ConversationMarkerSchema
{
	public const TABLE = 'social_conversation_marker';

	/** @return list<string> */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(80) NOT NULL PRIMARY KEY,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' conversation_id VARCHAR(512) NOT NULL,'
			. ' read_through_post_id VARCHAR(512) NULL,'
			. ' read_through_at VARCHAR(32) NULL,'
			. ' dismissed_through_post_id VARCHAR(512) NULL,'
			. ' dismissed_through_at VARCHAR(32) NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_pair ON ' . self::TABLE . ' (user_id, conversation_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
