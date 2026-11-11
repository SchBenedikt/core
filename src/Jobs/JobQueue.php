<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use DateTimeImmutable;
use Throwable;

/**
 * Durable queue of background work.
 *
 * The Nextcloud host backs this with the job table `occ` drains, the
 * standalone host with its own table and worker units. A job is reserved
 * before it runs, so two workers never handle the same row.
 */
interface JobQueue
{
	/**
	 * @param array<string, mixed> $payload
	 */
	public function push(string $type, array $payload, ?DateTimeImmutable $availableAt = null): Job;

	/**
	 * Reserves up to `$limit` due jobs for this worker.
	 *
	 * @return list<Job>
	 */
	public function reserve(int $limit, DateTimeImmutable $now): array;

	public function complete(Job $job): void;

	/**
	 * Records a failed attempt: the job is rescheduled with exponential
	 * backoff, or dead-lettered once the retry ceiling is reached.
	 */
	public function fail(Job $job, Throwable $error, DateTimeImmutable $now): void;

	/**
	 * Jobs waiting to run, including those waiting for a retry.
	 */
	public function pendingCount(): int;

	public function deadLetterCount(): int;
}
