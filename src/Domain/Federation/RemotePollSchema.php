<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of remote Questions and the local votes on them.
 *
 * The object id is the primary key: a retransmitted Create replaces the peer
 * view instead of adding a second question. Option titles and peer counts live
 * in one JSON column because they are replaced as a unit; our own votes are a
 * separate table so a local vote never rewrites the peer's counts.
 */
final class RemotePollSchema
{
	public const TABLE = 'social_remote_poll';

	public const VOTE_TABLE = 'social_remote_poll_vote';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' object_id VARCHAR(512) NOT NULL PRIMARY KEY,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' activity_id VARCHAR(512) NOT NULL,'
			. ' title VARCHAR(255) NULL,'
			. ' options TEXT NOT NULL,'
			. ' multiple SMALLINT NOT NULL,'
			. ' ends_at VARCHAR(20) NULL,'
			. ' closed_at VARCHAR(20) NULL,'
			. ' voters_count INTEGER NOT NULL DEFAULT 0,'
			. ' updated_at VARCHAR(20) NOT NULL,'
			. ' audience VARCHAR(16) NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_closure ON ' . self::TABLE . ' (ends_at)',
			'CREATE TABLE ' . self::VOTE_TABLE . ' ('
			. ' object_id VARCHAR(512) NOT NULL,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' positions VARCHAR(64) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' queued SMALLINT NOT NULL DEFAULT 0,'
			. ' PRIMARY KEY (object_id, user_id)'
			. ')',
			'CREATE INDEX ' . self::VOTE_TABLE . '_user ON ' . self::VOTE_TABLE . ' (user_id, created_at)',
		];
	}

	/**
	 * Columns added after the table first shipped. Hosts apply these to
	 * installations that already have `social_remote_poll`.
	 *
	 * @return list<string>
	 */
	public static function upgradeStatements(): array
	{
		return [
			'ALTER TABLE ' . self::TABLE . ' ADD COLUMN audience VARCHAR(16) NULL',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
