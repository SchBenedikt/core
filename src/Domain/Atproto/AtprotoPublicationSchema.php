<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of the AT Protocol publication table.
 *
 * (local_user_id, post_id) is the idempotency key. A separate unique index on
 * (local_user_id, rkey) protects the remote record key from being reused for
 * two local posts of the same account.
 */
final class AtprotoPublicationSchema
{
	public const TABLE = 'social_atproto_publication';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' rkey VARCHAR(13) NOT NULL,'
			. ' uri VARCHAR(512) NOT NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (local_user_id, post_id)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_record_key ON ' . self::TABLE
			. ' (local_user_id, rkey)',
			'CREATE INDEX ' . self::TABLE . '_published ON ' . self::TABLE
			. ' (published_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
