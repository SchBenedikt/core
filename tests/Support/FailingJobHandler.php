<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use RuntimeException;

/**
 * Always fails, so the retry path can be exercised.
 */
final class FailingJobHandler implements JobHandler
{
	public int $attempts = 0;

	public function handle(Job $job): void
	{
		$this->attempts++;

		throw new RuntimeException('handler failed on attempt ' . $this->attempts);
	}
}
