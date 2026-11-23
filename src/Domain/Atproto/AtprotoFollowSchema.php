<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;

/** Host-migrated local record of remote graph follows. */
final class AtprotoFollowSchema
{
	public const TABLE = 'social_atproto_follow';

	/** @return list<string> */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' account_did VARCHAR(512) NOT NULL,'
			. ' subject_did VARCHAR(512) NOT NULL,'
			. ' rkey VARCHAR(13) NOT NULL,'
			. ' uri VARCHAR(512) NULL,'
			. ' cid VARCHAR(256) NULL,'
			. ' state VARCHAR(16) NOT NULL,'
			. ' followed_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (local_user_id, subject_did)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_record_key ON ' . self::TABLE
			. ' (account_did, rkey)',
			'CREATE INDEX ' . self::TABLE . '_user_state ON ' . self::TABLE
			. ' (local_user_id, state, followed_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
