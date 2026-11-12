<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Jobs;

use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The behaviour every JobQueue implementation has to share.
 *
 * The PDO adapter runs this against a real SQLite file; the in-memory adapter
 * has the same rules in its own focused tests.
 */
abstract class JobQueueTestCase extends TestCase
{
	abstract protected function queue(): JobQueue;

	abstract protected function clock(): FrozenClock;

	public function testPushReturnsAPendingJob(): void
	{
		$job = $this->queue()->push('test', ['a' => 1]);

		self::assertNotSame('', $job->id);
		self::assertSame('test', $job->type);
		self::assertSame(['a' => 1], $job->payload);
		self::assertSame(0, $job->attempts);
		self::assertSame(1, $this->queue()->pendingCount());
	}

	public function testPushRefusesAnEmptyType(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->queue()->push('  ', []);
	}

	public function testReserveReturnsOnlyDueJobs(): void
	{
		$this->queue()->push('now', []);
		$this->queue()->push('later', [], Time::parse('2026-11-11T10:10:00Z'));

		$reserved = $this->queue()->reserve(10, $this->clock()->now());

		self::assertCount(1, $reserved);
		self::assertSame('now', $reserved[0]->type);
	}

	public function testReserveRespectsTheLimit(): void
	{
		$this->queue()->push('a', []);
		$this->queue()->push('b', []);
		$this->queue()->push('c', []);

		self::assertCount(2, $this->queue()->reserve(2, $this->clock()->now()));
		self::assertSame(1, $this->queue()->pendingCount());
	}

	public function testAReservedJobIsNotHandedOutTwice(): void
	{
		$this->queue()->push('a', []);

		$this->queue()->reserve(10, $this->clock()->now());

		self::assertSame([], $this->queue()->reserve(10, $this->clock()->now()));
	}

	public function testCompleteDropsTheReservation(): void
	{
		$this->queue()->push('a', []);
		$reserved = $this->queue()->reserve(10, $this->clock()->now());

		$this->queue()->complete($reserved[0]);

		self::assertSame(0, $this->queue()->pendingCount());
		self::assertSame(0, $this->queue()->deadLetterCount());
		self::assertSame([], $this->queue()->reserve(10, $this->clock()->now()));
	}

	public function testFailReschedulesWithBackoff(): void
	{
		$this->queue()->push('a', []);
		$reserved = $this->queue()->reserve(10, $this->clock()->now());

		$this->queue()->fail($reserved[0], new RuntimeException('boom'), $this->clock()->now());

		self::assertSame(1, $this->queue()->pendingCount());
		self::assertSame(0, $this->queue()->deadLetterCount());

		$this->clock()->advance(InMemoryJobQueue::BASE_BACKOFF_SECONDS - 1);
		self::assertSame([], $this->queue()->reserve(10, $this->clock()->now()));

		$this->clock()->advance(1);
		$retried = $this->queue()->reserve(10, $this->clock()->now());

		self::assertCount(1, $retried);
		self::assertSame(1, $retried[0]->attempts);
	}

	public function testFailDeadLettersAtTheCeiling(): void
	{
		$this->queue()->push('a', []);

		for ($attempt = 1; $attempt <= InMemoryJobQueue::MAX_ATTEMPTS; $attempt++) {
			$reserved = $this->queue()->reserve(10, $this->clock()->now());
			self::assertCount(1, $reserved, 'attempt ' . $attempt . ' should find the retried job');

			$this->queue()->fail($reserved[0], new RuntimeException('boom'), $this->clock()->now());
			$this->clock()->advance(InMemoryJobQueue::MAX_BACKOFF_SECONDS);
		}

		self::assertSame(0, $this->queue()->pendingCount());
		self::assertSame(1, $this->queue()->deadLetterCount());
	}
}
