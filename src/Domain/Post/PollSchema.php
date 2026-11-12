<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The poll tables, kept separate from the post tables.
 *
 * A fresh host creates these together with `PostSchema`; an existing host
 * adds them before it accepts polls. A post without a poll has no poll rows,
 * so the tables can be deployed before the feature is enabled. Deleting a
 * post is a soft delete and leaves the poll rows for the host's cleanup.
 */
final class PollSchema
{
	public const TABLE = 'social_poll';

	public const OPTION_TABLE = 'social_poll_option';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' post_id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' multiple SMALLINT NOT NULL,'
			. ' hide_totals SMALLINT NOT NULL,'
			. ' expires_at VARCHAR(20) NOT NULL,'
			. ' votes_count INTEGER NOT NULL DEFAULT 0,'
			. ' voters_count INTEGER NOT NULL DEFAULT 0'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_expiry ON ' . self::TABLE . ' (expires_at)',
			'CREATE TABLE ' . self::OPTION_TABLE . ' ('
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' position INTEGER NOT NULL,'
			. ' title VARCHAR(255) NOT NULL,'
			. ' votes_count INTEGER NOT NULL DEFAULT 0,'
			. ' PRIMARY KEY (post_id, position)'
			. ')',
			'CREATE INDEX ' . self::OPTION_TABLE . '_post ON ' . self::OPTION_TABLE . ' (post_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
