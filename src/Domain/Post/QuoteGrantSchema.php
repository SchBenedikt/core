<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of FEP-044f quote grants.
 *
 * Grant ids are derived from the quoted and quoting object, so one quote has
 * one row. The quoted actor is stored so a revocation can be authorised
 * without resolving the quoted object again.
 */
final class QuoteGrantSchema
{
	public const TABLE = 'social_quote_grant';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' quoted_object_id VARCHAR(1024) NOT NULL,'
			. ' interacting_object_id VARCHAR(1024) NOT NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' quoted_actor_id VARCHAR(255) NOT NULL,'
			. ' state VARCHAR(16) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_interaction ON ' . self::TABLE . ' (interacting_object_id)',
			'CREATE INDEX ' . self::TABLE . '_quoted ON ' . self::TABLE . ' (quoted_object_id, state)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
