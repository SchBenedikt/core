<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Jobs;

use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobHandlerRegistry;
use AlohaSocial\Core\Jobs\JobWorker;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\ArrayPsrLogger;
use AlohaSocial\Core\Tests\Support\FailingJobHandler;
use AlohaSocial\Core\Tests\Support\RecordingJobHandler;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

final class JobWorkerTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryJobQueue $queue;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));
	}

	public function testHandlesAPendingJob(): void
	{
		$handler = new RecordingJobHandler();
		$this->queue->push('test', ['n' => 1]);

		$handled = $this->worker($handler)->runOnce();

		self::assertSame(1, $handled);
		self::assertCount(1, $handler->jobs);
		self::assertSame(['n' => 1], $handler->jobs[0]->payload);
		self::assertSame(0, $this->queue->pendingCount());
	}

	public function testRespectsTheBatchLimit(): void
	{
		$handler = new RecordingJobHandler();
		$this->queue->push('test', []);
		$this->queue->push('test', []);
		$this->queue->push('test', []);

		$handled = $this->worker($handler)->runOnce(2);

		self::assertSame(2, $handled);
		self::assertCount(2, $handler->jobs);
		self::assertSame(1, $this->queue->pendingCount());
	}

	public function testDoesNotRunAJobThatIsNotDueYet(): void
	{
		$handler = new RecordingJobHandler();
		$this->queue->push('test', [], Time::parse('2026-11-11T10:05:00Z'));

		self::assertSame(0, $this->worker($handler)->runOnce());
		self::assertSame([], $handler->jobs);
	}

	public function testAFailingJobIsRetriedAndLogged(): void
	{
		$handler = new FailingJobHandler();
		$logger = new ArrayPsrLogger();
		$this->queue->push('test', []);

		self::assertSame(1, $this->worker($handler, $logger)->runOnce());

		self::assertSame(1, $this->queue->pendingCount());
		self::assertSame(0, $this->queue->deadLetterCount());
		self::assertSame(['warning'], $logger->levels());
		self::assertStringContainsString('failed', $logger->records[0]['message']);
	}

	public function testARetriedJobRunsAgainAfterTheBackoff(): void
	{
		$handler = new FailingJobHandler();
		$this->queue->push('test', []);

		$this->worker($handler)->runOnce();

		$this->clock->advance(InMemoryJobQueue::BASE_BACKOFF_SECONDS);
		$this->worker($handler)->runOnce();

		self::assertSame(2, $handler->attempts);
	}

	public function testAJobThatKeepsFailingIsDeadLettered(): void
	{
		$handler = new FailingJobHandler();
		$this->queue->push('test', []);
		$this->worker($handler)->runOnce();

		for ($attempt = 1; $attempt < InMemoryJobQueue::MAX_ATTEMPTS; $attempt++) {
			$this->clock->advance(InMemoryJobQueue::MAX_BACKOFF_SECONDS);
			$this->worker($handler)->runOnce();
		}

		self::assertSame(0, $this->queue->pendingCount());
		self::assertSame(1, $this->queue->deadLetterCount());
		self::assertSame(InMemoryJobQueue::MAX_ATTEMPTS, $handler->attempts);
	}

	public function testAnUnknownJobTypeEndsInTheDeadLetters(): void
	{
		$this->queue->push('nobody-handles-this', []);
		$worker = new JobWorker($this->queue, new JobHandlerRegistry(), $this->clock);

		$worker->runOnce();

		for ($attempt = 1; $attempt < InMemoryJobQueue::MAX_ATTEMPTS; $attempt++) {
			$this->clock->advance(InMemoryJobQueue::MAX_BACKOFF_SECONDS);
			$worker->runOnce();
		}

		self::assertSame(0, $this->queue->pendingCount());
		self::assertSame(1, $this->queue->deadLetterCount());
	}

	private function worker(RecordingJobHandler|FailingJobHandler $handler, ?ArrayPsrLogger $logger = null): JobWorker
	{
		$registry = (new JobHandlerRegistry())->with('test', $handler);

		return new JobWorker($this->queue, $registry, $this->clock, $logger ?? new ArrayPsrLogger());
	}
}
