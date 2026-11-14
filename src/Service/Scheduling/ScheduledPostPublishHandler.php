<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Scheduling;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use AlohaSocial\Core\Jobs\InvalidJobException;
use AlohaSocial\Core\Service\ScheduledPostService;

/**
 * Publishes one scheduled post when its job becomes due.
 *
 * The payload carries only the schedule id: everything else is read from the
 * row, so a reschedule between queueing and running is honoured. The handler
 * is safe to run twice — the service claims the row and the post id is
 * reserved.
 */
final class ScheduledPostPublishHandler implements JobHandler
{
	public function __construct(
		private readonly ScheduledPostService $scheduled,
	) {
	}

	public function handle(Job $job): void
	{
		$id = $job->payload['scheduled_post_id'] ?? null;
		if (!is_string($id) || trim($id) === '') {
			throw new InvalidJobException('invalid_payload', 'A scheduled publication needs a scheduled_post_id.');
		}

		$this->scheduled->publishDue(trim($id));
	}
}
