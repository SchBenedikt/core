<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Jobs\InvalidJobPayloadException;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;

use function is_string;

/**
 * Continues one bounded account-move batch.
 *
 * A missing event id is a terminal payload error. A missing row is also
 * terminal: no retry can create it. Any edge failure is left to the normal
 * worker retry, and the next run resumes from the ids already recorded.
 */
final class AccountMoveHandler implements JobHandler
{
	public function __construct(
		private readonly AccountMoveService $moves,
	) {
	}

	public function handle(Job $job): void
	{
		$eventId = $job->payload['event_id'] ?? null;
		if (!is_string($eventId) || $eventId === '') {
			throw new InvalidJobPayloadException('An account move job needs "event_id".');
		}

		$this->moves->continue($eventId);
	}
}
