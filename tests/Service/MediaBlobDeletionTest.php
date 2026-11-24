<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Service\MediaBlobDeleteHandler;
use AlohaSocial\Core\Service\MediaBlobDeletion;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

final class MediaBlobDeletionTest extends TestCase
{
	public function testFailedMediaDeleteQueuesAnIdempotentRetry(): void
	{
		$inner = new InMemoryBlobStore();
		$path = 'media/attachment-1/original.jpg';
		$inner->put($path, 'image');
		$blobs = new FailOnceMediaBlobStore($inner, $path);
		$clock = new FrozenClock(Time::parse('2026-11-24T12:00:00Z'));
		$queue = new InMemoryJobQueue($clock, new SequenceIdGenerator('job-'));

		(new MediaBlobDeletion($blobs, $queue))->delete($path);

		self::assertTrue($inner->exists($path));
		$jobs = $queue->reserve(1, $clock->now());
		self::assertCount(1, $jobs);
		self::assertSame(JobType::MEDIA_BLOB_DELETE, $jobs[0]->type);
		self::assertSame(['path' => $path], $jobs[0]->payload);

		(new MediaBlobDeleteHandler($blobs))->handle($jobs[0]);
		$queue->complete($jobs[0]);

		self::assertFalse($inner->exists($path));
		self::assertSame(0, $queue->pendingCount());
	}
}

final class FailOnceMediaBlobStore implements BlobStore
{
	private bool $failed = false;

	public function __construct(
		private readonly InMemoryBlobStore $inner,
		private readonly string $pathToFail,
	) {
	}

	public function put(string $path, string $contents): void
	{
		$this->inner->put($path, $contents);
	}

	public function read(string $path): string
	{
		return $this->inner->read($path);
	}

	public function exists(string $path): bool
	{
		return $this->inner->exists($path);
	}

	public function delete(string $path): void
	{
		if (!$this->failed && $path === $this->pathToFail) {
			$this->failed = true;
			throw new \RuntimeException('Simulated temporary object-store failure.');
		}

		$this->inner->delete($path);
	}

	public function size(string $path): int
	{
		return $this->inner->size($path);
	}

	public function list(string $prefix = ''): array
	{
		return $this->inner->list($prefix);
	}
}
