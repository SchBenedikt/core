<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Durable report queue.
 *
 * Evidence is JSON because it is a short, optional list of excerpts, not a
 * relation. The queue index is (status, created_at, id) so the moderator
 * list is one range scan and its cursor stays stable.
 */
final class ReportSchema
{
	public const TABLE = 'social_report';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' reporter_id VARCHAR(512) NOT NULL,'
			. ' target_type VARCHAR(16) NOT NULL,'
			. ' target_id VARCHAR(512) NOT NULL,'
			. ' reason VARCHAR(32) NOT NULL,'
			. ' comment TEXT NULL,'
			. ' evidence TEXT NOT NULL,'
			. ' status VARCHAR(16) NOT NULL,'
			. ' decision VARCHAR(32) NULL,'
			. ' moderator_id VARCHAR(255) NULL,'
			. ' resolution TEXT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::TABLE . '_queue ON ' . self::TABLE . ' (status, created_at, id)',
			'CREATE INDEX ' . self::TABLE . '_reporter ON ' . self::TABLE . ' (reporter_id, created_at)',
			'CREATE INDEX ' . self::TABLE . '_target ON ' . self::TABLE . ' (target_type, target_id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
