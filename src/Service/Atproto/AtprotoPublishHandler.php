<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use Psr\Log\LoggerInterface;

use function is_string;

/**
 * Runs one queued AT Protocol publication.
 *
 * Configuration and account problems are terminal for this job and are
 * logged without retrying. A PDS transport error is left to the queue's
 * backoff; the deterministic record key makes the retry an upsert.
 */
final class AtprotoPublishHandler implements JobHandler
{
	public function __construct(
		private readonly AtprotoPublishService $service,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Job $job): void
	{
		$userId = $job->payload['user_id'] ?? null;
		$postId = $job->payload['post_id'] ?? null;
		$update = $job->payload['update'] ?? false;

		if (!is_string($userId) || $userId === '' || !is_string($postId) || $postId === '' || !is_bool($update)) {
			throw new InvalidAtprotoJobPayloadException(
				'An AT Protocol publication job needs "user_id" and "post_id".',
			);
		}

		try {
			$this->service->publish($userId, $postId, update: $update);
		} catch (AtprotoPublishDisabledException
			| AtprotoNotConfiguredException
			| AtprotoAccountNotConnectedException
			| AtprotoPostRejectedException $exception
		) {
			$this->logger->info('Skipped AT Protocol publication.', [
				'post_id' => $postId,
				'reason' => $exception->getMessage(),
			]);
		} catch (PostNotFoundException) {
			$this->logger->info('Skipped AT Protocol publication because the local post is gone.', [
				'post_id' => $postId,
			]);
		}
	}
}
