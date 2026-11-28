<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

use function is_string;

/**
 * The replay guard in SQL.
 *
 * The primary key, not a preceding select, decides the claim: an insert either
 * wins or fails. When it fails we check that the row actually exists before
 * calling it a replay, so a database error is never silently turned into
 * "already seen".
 *
 * `seen_at` is the claim time. A claim older than the retention window is
 * removed on the next claim, which lets an old activity id be reused without
 * keeping the table forever.
 */
final class PdoActivityLog implements ActivityLog
{
	public const RETENTION_SECONDS = InMemoryActivityLog::RETENTION_SECONDS;

	public function __construct(
		private readonly Connection $db,
		private readonly int $retentionSeconds = self::RETENTION_SECONDS,
	) {
		if ($retentionSeconds < 1) {
			throw new InvalidArgumentException('The activity-log retention must be at least one second.');
		}
	}

	public function claim(string $activityId, DateTimeImmutable $now, ?string $digest = null): bool
	{
		if ($activityId === '') {
			throw new InvalidArgumentException('An activity id must not be empty.');
		}

		$now = Time::toSecondPrecision($now);
		$cutoff = Time::toSecondPrecision(
			$now->modify('-' . $this->retentionSeconds . ' seconds'),
		);

		$this->db->execute(
			'DELETE FROM ' . ActivityLogSchema::TABLE . ' WHERE seen_at <= :cutoff',
			['cutoff' => Time::format($cutoff)],
		);

		try {
			$this->db->execute(
				'INSERT INTO ' . ActivityLogSchema::TABLE . ' (activity_id, seen_at, digest)'
				. ' VALUES (:activity_id, :seen_at, :digest)',
				[
					'activity_id' => $activityId,
					'seen_at' => Time::format($now),
					'digest' => $digest,
				],
			);

			return true;
		} catch (Throwable $exception) {
			// A duplicate is the normal replay answer. Ask the table rather
			// than treating every database error as a duplicate.
			$existing = $this->db->fetchValue(
				'SELECT 1 FROM ' . ActivityLogSchema::TABLE . ' WHERE activity_id = :activity_id',
				['activity_id' => $activityId],
			);

			if ($existing !== null) {
				return false;
			}

			throw $exception;
		}
	}

	public function digest(string $activityId): ?string
	{
		$value = $this->db->fetchValue(
			'SELECT digest FROM ' . ActivityLogSchema::TABLE . ' WHERE activity_id = :activity_id',
			['activity_id' => $activityId],
		);

		return is_string($value) ? $value : null;
	}

	public function release(string $activityId): void
	{
		$this->db->execute(
			'DELETE FROM ' . ActivityLogSchema::TABLE . ' WHERE activity_id = :activity_id',
			['activity_id' => $activityId],
		);
	}
}
