<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;

/** Durable IDs for successfully published likes and reposts. */
final class AtprotoInteractionSchema
{
	public const TABLE = 'social_atproto_interaction';

	/** @return list<string> */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' local_user_id VARCHAR(64) NOT NULL,'
			. ' account_did VARCHAR(255) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' kind VARCHAR(16) NOT NULL,'
			. ' rkey VARCHAR(512) NOT NULL,'
			. ' uri VARCHAR(512) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (local_user_id, account_did, post_id, kind)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_record ON ' . self::TABLE . ' (account_did, rkey)',
			'CREATE INDEX ' . self::TABLE . '_user ON ' . self::TABLE . ' (local_user_id, created_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
