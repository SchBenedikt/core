<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

use AlohaSocial\Core\Domain\Account\AccountDeletionSweeper;
use AlohaSocial\Core\Domain\Account\AccountWriteGuard;
use AlohaSocial\Core\Platform\Clock\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runs due jobs.
 *
 * One job that fails never stops the batch. The worker separates the two
 * failures that used to share one catch block:
 *
 * - the handler failed, so the queue retries with backoff (or dead-letters a
 *   terminal payload/type error immediately);
 * - the handler succeeded but removing the reservation failed, so the handler
 *   is not run again and the job is not marked failed. The durable queue's
 *   reservation TTL recovers it if this process dies.
 */
final class JobWorker
{
	public function __construct(
		private readonly JobQueue $queue,
		private readonly JobHandlerRegistry $handlers,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger = new NullLogger(),
		private readonly ?AccountWriteGuard $accountWriteGuard = null,
		private readonly ?AccountDeletionSweeper $accountDeletionSweeper = null,
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

			if ($this->isBlockedByAccountDeletion($job)) {
				try {
					$this->queue->complete($job);
				} catch (Throwable $exception) {
					$this->logger->error(
						'Job {id} of type {type} was cancelled by an account deletion but could not be removed: {message}',
						[
							'id' => $job->id,
							'type' => $job->type,
							'message' => $exception->getMessage(),
							'exception' => $exception,
						],
					);
				}

				continue;
			}

			try {
				$this->handlers->handle($job);
			} catch (TerminalJobException $exception) {
				$this->logger->warning(
					'Job {id} of type {type} stopped with terminal error {code}: {message}',
					[
						'id' => $job->id,
						'type' => $job->type,
						'code' => $exception->errorCode(),
						'message' => $exception->getMessage(),
						'attempt' => $job->attempts + 1,
						'terminal' => true,
						'exception' => $exception,
					],
				);

				$this->sweepIfBlocked($job);
				$this->queue->deadLetter($job, $exception, $this->clock->now());

				continue;
			} catch (Throwable $exception) {
				$this->logger->warning(
					'Job {id} of type {type} failed with {code}: {message}',
					[
						'id' => $job->id,
						'type' => $job->type,
						'code' => JobFailureCode::HANDLER_FAILED,
						'message' => $exception->getMessage(),
						'attempt' => $job->attempts + 1,
						'terminal' => false,
						'exception' => $exception,
					],
				);

				$this->sweepIfBlocked($job);
				$this->queue->fail($job, $exception, $this->clock->now());

				continue;
			}

			$this->sweepIfBlocked($job);

			try {
				$this->queue->complete($job);
			} catch (Throwable $exception) {
				// The side effect already happened. Calling fail() here would
				// make the queue run the handler again, duplicating whatever it
				// wrote. Leave the reservation for the TTL-based recovery and
				// make the lost completion visible.
				$this->logger->error(
					'Job {id} of type {type} completed but could not be removed with {code}: {message}',
					[
						'id' => $job->id,
						'type' => $job->type,
						'code' => JobFailureCode::COMPLETION_FAILED,
						'message' => $exception->getMessage(),
						'exception' => $exception,
					],
				);
			}
		}

		return $handled;
	}

	private function isBlockedByAccountDeletion(Job $job): bool
	{
		if (!$job->subject?->isAccount() || $this->accountWriteGuard === null) {
			return false;
		}

		return $this->accountWriteGuard->accountWriteStatus($job->subject->id) !== null;
	}

	private function sweepIfBlocked(Job $job): void
	{
		if ($this->accountDeletionSweeper === null || !$this->isBlockedByAccountDeletion($job)) {
			return;
		}

		try {
			$this->accountDeletionSweeper->sweep($job->subject->id);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Job {id} of type {type} completed for a deleted account, but its late writes could not be swept: {message}',
				[
					'id' => $job->id,
					'type' => $job->type,
					'message' => $exception->getMessage(),
					'exception' => $exception,
				],
			);
		}
	}
}
