<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Story;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use Psr\Log\LoggerInterface;

/**
 * Runs the idempotent story expiry sweep. A restart or a duplicate job can
 * only re-delete rows that are already gone; it cannot resurrect one.
 */
final class StoryExpireHandler implements JobHandler
{
	public function __construct(
		private readonly StoryService $stories,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Job $job): void
	{
		$expired = $this->stories->expireDue();
		$this->logger->info('Story expiry sweep finished.', [
			'job_id' => $job->id,
			'expired' => $expired,
		]);
	}
}
