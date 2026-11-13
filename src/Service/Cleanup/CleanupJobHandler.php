<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Cleanup;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use Psr\Log\LoggerInterface;

/**
 * Runs one queued retention cleanup.
 *
 * A malformed payload is a terminal configuration error, not a transient
 * failure: it is logged with its stable code and the job completes instead of
 * retrying forever. Database/blob failures propagate so the queue applies its
 * normal backoff.
 */
final class CleanupJobHandler implements JobHandler
{
	public function __construct(
		private readonly CleanupService $cleanup,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Job $job): void
	{
		try {
			$options = CleanupOptions::fromArray($job->payload);
		} catch (InvalidCleanupOptionsException $exception) {
			$this->logger->warning('Skipped cleanup job: {code}', [
				'code' => $exception->errorCode,
				'message' => $exception->getMessage(),
				'job_id' => $job->id,
			]);

			return;
		}

		$report = $this->cleanup->run($options);
		$this->logger->info('Cleanup job finished.', [
			'job_id' => $job->id,
			'run_at' => $report->runAt->format('c'),
			'dry_run' => $report->dryRun,
			'matched' => $report->matched(),
			'deleted' => $report->deleted(),
			'remaining' => $report->remaining(),
		]);
	}
}
