<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use AlohaSocial\Core\Platform\Clock\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runs due jobs.
 *
 * One job that fails never stops the batch: the failure is logged, the job is
 * handed back to the queue with a backoff, and the worker continues. The
 * caller decides how often to call `runOnce()` — cron every minute, a systemd
 * service in a loop, or a test.
 */
final class JobWorker
{
	public function __construct(
		private readonly JobQueue $queue,
		private readonly JobHandlerRegistry $handlers,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger = new NullLogger(),
	) {
	}

	/**
	 * @return int how many jobs were handled, successfully or not
	 */
	public function runOnce(int $limit = 10): int
	{
		$reserved = $this->queue->reserve($limit, $this->clock->now());
		$handled = 0;

		foreach ($reserved as $job) {
			$handled++;

			try {
				$this->handlers->handle($job);
				$this->queue->complete($job);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'Job {id} of type {type} failed: {message}',
					[
						'id' => $job->id,
						'type' => $job->type,
						'message' => $exception->getMessage(),
						'attempt' => $job->attempts + 1,
						'exception' => $exception,
					],
				);

				$this->queue->fail($job, $exception, $this->clock->now());
			}
		}

		return $handled;
	}
}
