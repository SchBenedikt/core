<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;
use Throwable;
use UnexpectedValueException;

/**
 * The durable job queue in SQL.
 *
 * A reservation is a conditional update inside a transaction, not a
 * select-then-trust: two workers that see the same row can both try, but only
 * one update affects a row. A reservation older than the TTL is recoverable,
 * so a worker that crashes between reserve and complete does not strand the
 * job forever.
 *
 * Retry and dead-letter rules match {@see InMemoryJobQueue}; the constants are
 * reused so the two implementations cannot silently diverge.
 */
final class PdoJobQueue implements JobQueue
{
	/**
	 * A reservation older than this is considered abandoned by a crashed
	 * worker and may be taken again.
	 */
	public const RESERVATION_SECONDS = 300;

	public const MAX_ATTEMPTS = InMemoryJobQueue::MAX_ATTEMPTS;

	public const BASE_BACKOFF_SECONDS = InMemoryJobQueue::BASE_BACKOFF_SECONDS;

	public const MAX_BACKOFF_SECONDS = InMemoryJobQueue::MAX_BACKOFF_SECONDS;

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	public function push(string $type, array $payload, ?DateTimeImmutable $availableAt = null): Job
	{
		if (trim($type) === '') {
			throw new \InvalidArgumentException('A job needs a type.');
		}

		$job = new Job(
			$this->ids->generate(),
			$type,
			$payload,
			Time::toSecondPrecision($availableAt ?? $this->clock->now()),
		);

		$this->db->execute(
			'INSERT INTO ' . JobSchema::TABLE
			. ' (id, type, payload, available_at, attempts, reserved_at, dead_at)'
			. ' VALUES (:id, :type, :payload, :available_at, 0, NULL, NULL)',
			[
				'id' => $job->id,
				'type' => $job->type,
				'payload' => json_encode($job->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
				'available_at' => Time::format($job->availableAt),
			],
		);

		return $job;
	}

	public function reserve(int $limit, DateTimeImmutable $now): array
	{
		if ($limit < 1) {
			return [];
		}

		$now = Time::toSecondPrecision($now);
		$staleBefore = Time::toSecondPrecision(
			$now->modify('-' . self::RESERVATION_SECONDS . ' seconds'),
		);

		return $this->db->transaction(function (Connection $db) use ($limit, $now, $staleBefore): array {
			$rows = $db->fetchAll(
				'SELECT id, type, payload, available_at, attempts FROM ' . JobSchema::TABLE
				. ' WHERE dead_at IS NULL'
				. ' AND available_at <= :now'
				. ' AND (reserved_at IS NULL OR reserved_at <= :stale)'
				. ' ORDER BY available_at ASC, id ASC'
				. ' LIMIT ' . max(1, $limit),
				[
					'now' => Time::format($now),
					'stale' => Time::format($staleBefore),
				],
			);

			$jobs = [];
			foreach ($rows as $row) {
				$claimed = $db->execute(
					'UPDATE ' . JobSchema::TABLE
					. ' SET reserved_at = :now'
					. ' WHERE id = :id AND dead_at IS NULL'
					. ' AND (reserved_at IS NULL OR reserved_at <= :stale)',
					[
						'id' => (string) $row['id'],
						'now' => Time::format($now),
						'stale' => Time::format($staleBefore),
					],
				);

				// A concurrent worker won the row between our select and
				// update. It is not ours to return.
				if ($claimed === 1) {
					$jobs[] = $this->hydrate($row);
				}
			}

			return $jobs;
		});
	}

	public function complete(Job $job): void
	{
		$this->db->execute(
			'DELETE FROM ' . JobSchema::TABLE . ' WHERE id = :id',
			['id' => $job->id],
		);
	}

	public function fail(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
		$attempts = $job->attempts + 1;

		if ($attempts >= self::MAX_ATTEMPTS) {
			$this->db->execute(
				'UPDATE ' . JobSchema::TABLE
				. ' SET attempts = :attempts, reserved_at = NULL, dead_at = :now'
				. ' WHERE id = :id',
				[
					'id' => $job->id,
					'attempts' => $attempts,
					'now' => Time::format($now),
				],
			);

			return;
		}

		$delay = min(
			self::MAX_BACKOFF_SECONDS,
			self::BASE_BACKOFF_SECONDS * (2 ** ($attempts - 1)),
		);
		$availableAt = Time::toSecondPrecision($now->modify('+' . $delay . ' seconds'));

		$this->db->execute(
			'UPDATE ' . JobSchema::TABLE
			. ' SET attempts = :attempts, available_at = :available_at,'
			. ' reserved_at = NULL, dead_at = NULL'
			. ' WHERE id = :id',
			[
				'id' => $job->id,
				'attempts' => $attempts,
				'available_at' => Time::format($availableAt),
			],
		);
	}

	public function pendingCount(): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . JobSchema::TABLE
			. ' WHERE dead_at IS NULL AND reserved_at IS NULL',
		);
	}

	public function deadLetterCount(): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . JobSchema::TABLE . ' WHERE dead_at IS NOT NULL',
		);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @throws JsonException when a stored payload is not valid JSON
	 */
	private function hydrate(array $row): Job
	{
		$payload = json_decode((string) $row['payload'], true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($payload)) {
			throw new UnexpectedValueException('A stored job payload must be a JSON object.');
		}

		return new Job(
			id: (string) $row['id'],
			type: (string) $row['type'],
			payload: $payload,
			availableAt: Time::parse((string) $row['available_at']),
			attempts: (int) $row['attempts'],
		);
	}
}
