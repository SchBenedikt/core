<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Jobs;

use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use AlohaSocial\Core\Support\Time;
use PDO;
use RuntimeException;

/**
 * The shared queue contract against a real SQLite file, plus the restart
 * guarantees the in-memory queue cannot provide.
 */
final class PdoJobQueueTest extends JobQueueTestCase
{
	private FrozenClock $clock;

	private JobQueue $queue;

	private string $databasePath;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->databasePath = sys_get_temp_dir() . '/alohasocial-jobs-' . bin2hex(random_bytes(8)) . '.sqlite';

		$connection = $this->connection();
		JobSchema::create($connection);

		$this->queue = new PdoJobQueue(
			$connection,
			$this->clock,
			new SequenceIdGenerator('job-'),
		);
	}

	protected function tearDown(): void
	{
		@unlink($this->databasePath);
	}

	public function testAJobSurvivesAFreshConnectionAndCompleteRemovesIt(): void
	{
		$job = $this->queue->push('deliver', ['inbox' => 'https://remote.example/inbox']);

		$fresh = new PdoJobQueue($this->connection(), $this->clock, new RandomIdGenerator());
		$reserved = $fresh->reserve(10, $this->clock->now());

		self::assertCount(1, $reserved);
		self::assertSame($job->id, $reserved[0]->id);
		self::assertSame('deliver', $reserved[0]->type);
		self::assertSame(['inbox' => 'https://remote.example/inbox'], $reserved[0]->payload);
		self::assertSame(0, $fresh->pendingCount());

		$fresh->complete($reserved[0]);

		$after = new PdoJobQueue($this->connection(), $this->clock, new RandomIdGenerator());
		self::assertSame(0, $after->pendingCount());
		self::assertSame([], $after->reserve(10, $this->clock->now()));
	}

	public function testDeadLetterRecordsASanitizedLastError(): void
	{
		$this->queue->push('deliver', []);
		$reserved = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $reserved);

		$this->queue->deadLetter(
			$reserved[0],
			new RuntimeException('remote inbox rejected the activity'),
			$this->clock->now(),
		);

		$error = $this->connection()->fetchValue(
			'SELECT last_error FROM ' . JobSchema::TABLE . ' WHERE id = :id',
			['id' => $reserved[0]->id],
		);
		self::assertSame('RuntimeException: remote inbox rejected the activity', $error);
	}

	public function testTerminalFailRecordsLastErrorAndRetryKeepsItVisible(): void
	{
		$this->queue->push('deliver', []);
		$reserved = $this->queue->reserve(10, $this->clock->now());

		$this->queue->fail($reserved[0], new RuntimeException('first failure'), $this->clock->now());
		$error = $this->connection()->fetchValue(
			'SELECT last_error FROM ' . JobSchema::TABLE . ' WHERE id = :id',
			['id' => $reserved[0]->id],
		);
		self::assertSame('RuntimeException: first failure', $error);
	}

	public function testLastErrorIsTruncatedToTheColumnWidth(): void
	{
		$this->queue->push('deliver', []);
		$reserved = $this->queue->reserve(10, $this->clock->now());

		$this->queue->deadLetter(
			$reserved[0],
			new RuntimeException(str_repeat('x', 500)),
			$this->clock->now(),
		);

		$error = (string) $this->connection()->fetchValue(
			'SELECT last_error FROM ' . JobSchema::TABLE . ' WHERE id = :id',
			['id' => $reserved[0]->id],
		);
		self::assertSame(200, strlen($error));
	}

	public function testAFailedJobIsRetriedAfterAFreshConnection(): void
	{
		$this->queue->push('deliver', ['inbox' => 'https://remote.example/inbox']);

		$fresh = new PdoJobQueue($this->connection(), $this->clock, new RandomIdGenerator());
		$reserved = $fresh->reserve(10, $this->clock->now());
		self::assertCount(1, $reserved);

		$fresh->fail($reserved[0], new \RuntimeException('peer is down'), $this->clock->now());

		$afterFailure = new PdoJobQueue($this->connection(), $this->clock, new RandomIdGenerator());
		self::assertSame(1, $afterFailure->pendingCount());
		self::assertSame([], $afterFailure->reserve(10, $this->clock->now()));

		$this->clock->advance(\AlohaSocial\Core\Jobs\InMemoryJobQueue::BASE_BACKOFF_SECONDS);

		$retry = new PdoJobQueue($this->connection(), $this->clock, new RandomIdGenerator());
		$retried = $retry->reserve(10, $this->clock->now());

		self::assertCount(1, $retried);
		self::assertSame(1, $retried[0]->attempts);
		self::assertSame($reserved[0]->id, $retried[0]->id);
	}

	protected function queue(): JobQueue
	{
		return $this->queue;
	}

	protected function clock(): FrozenClock
	{
		return $this->clock;
	}

	private function connection(): Connection
	{
		return new PdoConnection(new PDO('sqlite:' . $this->databasePath));
	}
}
