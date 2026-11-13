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
 * Rejects every push, so a caller's transaction can be forced to roll back
 * after its database writes have already happened.
 */
final class RejectingJobQueue implements JobQueue
{
	public function push(string $type, array $payload, ?DateTimeImmutable $availableAt = null): Job
	{
		throw new RuntimeException('The delivery queue is unavailable.');
	}

	public function reserve(int $limit, DateTimeImmutable $now): array
	{
		return [];
	}

	public function complete(Job $job): void
	{
	}

	public function fail(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
	}

	public function deadLetter(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
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
