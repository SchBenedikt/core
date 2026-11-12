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
 * Runs one queued AT Protocol remote delete.
 *
 * Configuration, a disconnected account or a hard-deleted local post is
 * terminal and logged; a PDS transport/refusal failure is retried by the
 * queue. A retry after a lost success finds no publication row or receives a
 * 404 from the PDS, both of which are idempotent success.
 */
final class AtprotoDeleteHandler implements JobHandler
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

		if (!is_string($userId) || $userId === '' || !is_string($postId) || $postId === '') {
			throw new InvalidAtprotoJobPayloadException(
				'An AT Protocol delete job needs "user_id" and "post_id".',
			);
		}

		try {
			$this->service->deleteRemote($userId, $postId);
		} catch (AtprotoPublishDisabledException
			| AtprotoNotConfiguredException
			| AtprotoAccountNotConnectedException $exception
		) {
			$this->logger->info('Skipped AT Protocol remote delete.', [
				'post_id' => $postId,
				'reason' => $exception->getMessage(),
			]);
		} catch (PostNotFoundException) {
			$this->logger->warning('Refused AT Protocol remote delete for a foreign post.', [
				'post_id' => $postId,
			]);
		}
	}
}
