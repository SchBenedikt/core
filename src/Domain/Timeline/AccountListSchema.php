<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of private account lists.
 *
 * Membership is a separate table so a list can be renamed without touching
 * its rows and an account can belong to several lists. The composite primary
 * key is the idempotency boundary. Timestamps use the same fixed-width form as
 * every other cursor; the list and membership indexes serve the newest-first
 * list and account pages.
 */
final class AccountListSchema
{
	public const TABLE = 'social_account_list';

	public const MEMBER_TABLE = 'social_account_list_member';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' title VARCHAR(255) NOT NULL,'
			. ' replies_policy VARCHAR(16) NOT NULL,'
			. ' exclusive SMALLINT NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE
			. ' (user_id, created_at, id)',
			'CREATE TABLE ' . self::MEMBER_TABLE . ' ('
			. ' list_id VARCHAR(64) NOT NULL,'
			. ' account_id VARCHAR(255) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (list_id, account_id)'
			. ')',
			'CREATE INDEX ' . self::MEMBER_TABLE . '_account ON ' . self::MEMBER_TABLE
			. ' (account_id)',
			'CREATE INDEX ' . self::MEMBER_TABLE . '_list ON ' . self::MEMBER_TABLE
			. ' (list_id, created_at, account_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
