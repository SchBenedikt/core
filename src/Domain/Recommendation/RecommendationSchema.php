<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Platform\Db\Connection;

/**
 * Owner-scoped recommendation state.
 *
 * Preferences are one row per reader, feedback is one row per reader and
 * candidate (so a changed verdict is an upsert), and signals are an append-only
 * bounded history. There is deliberately no cross-user interest table and no
 * snapshot table: a snapshot is a short-lived cache, not authoritative data.
 */
final class RecommendationSchema
{
	public const PREFERENCE_TABLE = 'social_recommendation_preference';

	public const FEEDBACK_TABLE = 'social_recommendation_feedback';

	public const SIGNAL_TABLE = 'social_recommendation_signal';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::PREFERENCE_TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL PRIMARY KEY,'
			. ' enabled SMALLINT NOT NULL DEFAULT 1,'
			. ' behavioral_consent SMALLINT NOT NULL DEFAULT 0,'
			. ' interests_json TEXT NOT NULL,'
			. ' languages_json TEXT NOT NULL,'
			. ' updated_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE TABLE ' . self::FEEDBACK_TABLE . ' ('
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' candidate_id VARCHAR(700) NOT NULL,'
			. ' action VARCHAR(16) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (user_id, candidate_id)'
			. ')',
			'CREATE INDEX ' . self::FEEDBACK_TABLE . '_owner ON ' . self::FEEDBACK_TABLE
			. ' (user_id, created_at, candidate_id)',
			'CREATE TABLE ' . self::SIGNAL_TABLE . ' ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' user_id VARCHAR(255) NOT NULL,'
			. ' kind VARCHAR(16) NOT NULL,'
			. ' candidate_id VARCHAR(700) NOT NULL,'
			. ' created_at VARCHAR(20) NOT NULL'
			. ')',
			'CREATE INDEX ' . self::SIGNAL_TABLE . '_owner ON ' . self::SIGNAL_TABLE
			. ' (user_id, created_at, id)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}
}
