<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/** Deletes media objects and durably retries failures when a worker queue is available. */
final readonly class MediaBlobDeletion
{
	public function __construct(
		private BlobStore $blobs,
		private ?JobQueue $queue = null,
		private LoggerInterface $logger = new NullLogger(),
	) {
	}

	public function delete(string $path): void
	{
		if ($path === '') {
			return;
		}

		try {
			$this->blobs->delete($path);
		} catch (Throwable $exception) {
			if ($this->queue !== null) {
				try {
					$this->queue->push(JobType::MEDIA_BLOB_DELETE, ['path' => $path]);
					$this->logger->warning('Media blob deletion was queued for retry.', [
						'path' => $path,
						'reason' => 'media_blob_delete_failed',
						'exception' => $exception,
					]);
					return;
				} catch (Throwable $queueException) {
					$this->logger->error('Media blob deletion could not be queued for retry.', [
						'path' => $path,
						'reason' => 'media_blob_delete_retry_queue_failed',
						'exception' => $queueException,
					]);
				}
			}

			$this->logger->warning('Media blob deletion failed without a retry queue.', [
				'path' => $path,
				'reason' => 'media_blob_delete_failed',
				'exception' => $exception,
			]);
		}
	}
}
