<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use Psr\Log\LoggerInterface;

use function is_int;

/**
 * Runs one bounded AT Protocol sync page from the queue.
 *
 * A source/transport failure is retryable and left to the queue backoff. A
 * source mismatch or identity conflict cannot be fixed by repeating the same
 * event; it is logged and the job completes so a poison page is visible
 * without blocking the worker forever.
 */
final class AtprotoSyncHandler implements JobHandler
{
	public function __construct(
		private readonly AtprotoSyncService $service,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Job $job): void
	{
		$limit = $job->payload['limit'] ?? AtprotoSyncService::DEFAULT_LIMIT;
		if (!is_int($limit) || $limit < 1 || $limit > AtprotoSyncService::MAX_LIMIT) {
			throw new InvalidAtprotoSyncJobPayloadException(
				'An AT Protocol sync job needs an optional integer limit between 1 and '
				. AtprotoSyncService::MAX_LIMIT . '.',
			);
		}

		try {
			$result = $this->service->sync($limit);
		} catch (AtprotoSyncException $exception) {
			if ($exception->reason === AtprotoSyncException::SOURCE_MISMATCH
				|| $exception->reason === AtprotoSyncException::IDENTITY_CONFLICT
			) {
				$this->logger->warning('Skipped a conflicting AT Protocol sync page.', [
					'reason' => $exception->reason,
				]);

				return;
			}

			throw $exception;
		}

		$this->logger->info('Applied an AT Protocol sync page.', [
			'applied' => $result->applied,
			'skipped' => $result->skipped,
			'conflicted' => $result->conflicted,
			'deleted' => $result->deleted,
			'reconcile_required' => $result->reconcileRequired(),
		]);
	}
}
