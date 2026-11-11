<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;

/**
 * Records every job it is handed and succeeds.
 */
final class RecordingJobHandler implements JobHandler
{
	/**
	 * @var list<Job>
	 */
	public array $jobs = [];

	public function handle(Job $job): void
	{
		$this->jobs[] = $job;
	}
}
