<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobQueue;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Hands out one job, then fails when its completion is removed.
 *
 * This is the failure the worker must not turn into a retry: the handler has
 * already produced its side effect.
 */
final class CompletionFailingJobQueue implements JobQueue
{
	public int $failCalls = 0;

	public int $deadLetterCalls = 0;

	private bool $reserved = false;

	public function __construct(
		private readonly Job $job,
	) {
	}

	public function push(string $type, array $payload, ?DateTimeImmutable $availableAt = null): Job
	{
		return $this->job;
	}

	public function reserve(int $limit, DateTimeImmutable $now): array
	{
		if ($this->reserved) {
			return [];
		}

		$this->reserved = true;

		return [$this->job];
	}

	public function complete(Job $job): void
	{
		throw new RuntimeException('The job row could not be removed.');
	}

	public function fail(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
		$this->failCalls++;
		$this->reserved = false;
	}

	public function deadLetter(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
		$this->deadLetterCalls++;
		$this->reserved = false;
	}

	public function pendingCount(): int
	{
		return 0;
	}

	public function deadLetterCount(): int
	{
		return 0;
	}
}
