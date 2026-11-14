<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * The canonical schema of federated reactions.
 *
 * `local_post_id` is filled only for inbound reactions: it is the post whose
 * audience was checked, so a later count never has to parse the note URL
 * again. The unique key is the identity rule from {@see Reaction}; `emoji` is
 * an empty string for a like so the key works on every SQL engine.
 */
final class ReactionSchema
{
	public const TABLE = 'social_reaction';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' direction VARCHAR(3) NOT NULL,'
			. ' subject_id VARCHAR(255) NOT NULL,'
			. ' object_id VARCHAR(512) NOT NULL,'
			. ' local_post_id VARCHAR(64) NULL,'
			. ' kind VARCHAR(16) NOT NULL,'
			. ' emoji VARCHAR(64) NOT NULL DEFAULT \'\','
			. ' icon_url VARCHAR(512) NULL,'
			. ' activity_id VARCHAR(512) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' UNIQUE (direction, subject_id, object_id, kind, emoji)'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_post ON ' . self::TABLE . ' (local_post_id, kind)',
			'CREATE INDEX ' . self::TABLE . '_object ON ' . self::TABLE . ' (object_id)',
			'CREATE INDEX ' . self::TABLE . '_activity ON ' . self::TABLE . ' (activity_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
