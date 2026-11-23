<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/** Durable, actor-specific remote block policy. */
final class InboundBlockSchema
{
	public const TABLE = 'social_inbound_block';

	/** @return list<string> */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' activity_id VARCHAR(512) NOT NULL,'
			. ' PRIMARY KEY (local_user_id, actor_id)'
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
