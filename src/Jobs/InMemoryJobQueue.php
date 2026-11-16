<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

/**
 * A queue that lives in the process.
 *
 * It exists so the delivery path can be tested end to end without a
 * database, and it implements the same retry and dead-letter rules the
 * durable queue must implement.
 */
final class InMemoryJobQueue implements JobQueue
{
	/** After this many failed attempts a job is not retried again. */
	public const MAX_ATTEMPTS = 5;

	/** The first retry waits this long; every further one doubles it. */
	public const BASE_BACKOFF_SECONDS = 30;

	public const MAX_BACKOFF_SECONDS = 3600;

	/**
	 * @var array<string, Job>
	 */
	private array $pending = [];

	/**
	 * @var array<string, Job>
	 */
	private array $reserved = [];

	/**
	 * @var array<string, Job>
	 */
	private array $dead = [];

	public function __construct(
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	public function push(
		string $type,
		array $payload,
		?DateTimeImmutable $availableAt = null,
		?JobSubject $subject = null,
	): Job {
		if (trim($type) === '') {
			throw new InvalidJobException(JobFailureCode::INVALID_TYPE, 'A job needs a type.');
		}

		$job = new Job(
			$this->ids->generate(),
			$type,
			$payload,
			Time::toSecondPrecision($availableAt ?? $this->clock->now()),
			0,
			$subject,
		);

		$this->pending[$job->id] = $job;

		return $job;
	}

	public function reserve(int $limit, DateTimeImmutable $now): array
	{
		if ($limit < 1) {
			return [];
		}

		$reserved = [];

		foreach ($this->ordered() as $job) {
			if (count($reserved) >= $limit) {
				break;
			}

			if ($job->availableAt > $now) {
				continue;
			}

			unset($this->pending[$job->id]);
			$this->reserved[$job->id] = $job;
			$reserved[] = $job;
		}

		return $reserved;
	}

	public function cancelBySubject(JobSubject $subject): int
	{
		$cancelled = 0;
		foreach ([$this->pending, $this->reserved] as $jobs) {
			foreach ($jobs as $id => $job) {
				if ($job->subject?->type === $subject->type && $job->subject->id === $subject->id) {
					unset($this->pending[$id], $this->reserved[$id]);
					$cancelled++;
				}
			}
		}

		return $cancelled;
	}

	public function complete(Job $job): void
	{
		unset($this->reserved[$job->id]);
	}

	public function deadLetter(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
		if (!isset($this->reserved[$job->id])) {
			return;
		}

		unset($this->reserved[$job->id]);

		$this->dead[$job->id] = new Job(
			$job->id,
			$job->type,
			$job->payload,
			$job->availableAt,
			$job->attempts + 1,
			$job->subject,
		);
	}

	public function fail(Job $job, Throwable $error, DateTimeImmutable $now): void
	{
		unset($this->reserved[$job->id]);

		$attempts = $job->attempts + 1;
		$failed = new Job($job->id, $job->type, $job->payload, $job->availableAt, $attempts, $job->subject);

		if ($attempts >= self::MAX_ATTEMPTS) {
			$this->dead[$job->id] = $failed;

			return;
		}

		$delay = min(
			self::MAX_BACKOFF_SECONDS,
			self::BASE_BACKOFF_SECONDS * (2 ** ($attempts - 1)),
		);

		$this->pending[$job->id] = new Job(
			$job->id,
			$job->type,
			$job->payload,
			Time::toSecondPrecision($now->modify('+' . $delay . ' seconds')),
			$attempts,
			$job->subject,
		);
	}

	/**
	 * The jobs waiting to run, in the order they will be handed out.
	 *
	 * @return list<Job>
	 */
	public function pending(): array
	{
		return $this->ordered();
	}

	public function pendingCount(): int
	{
		return count($this->pending);
	}

	public function deadLetterCount(): int
	{
		return count($this->dead);
	}

	/**
	 * @return list<Job>
	 */
	private function ordered(): array
	{
		$jobs = array_values($this->pending);
		usort($jobs, static function (Job $a, Job $b): int {
			$byTime = $a->availableAt <=> $b->availableAt;

			return $byTime !== 0 ? $byTime : strcmp($a->id, $b->id);
		});

		return $jobs;
	}
}
