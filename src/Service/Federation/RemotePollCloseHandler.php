<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Jobs\InvalidJobPayloadException;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use AlohaSocial\Core\Platform\Clock\Clock;

use function is_string;

/**
 * Marks one remote poll closed at its scheduled end.
 *
 * The job is idempotent: a poll already closed by the peer's `closed`
 * timestamp, or by an earlier run, is a successful no-op.
 */
final class RemotePollCloseHandler implements JobHandler
{
	public function __construct(
		private readonly RemotePollService $polls,
		private readonly Clock $clock,
	) {
	}

	public function handle(Job $job): void
	{
		$objectId = $job->payload['object_id'] ?? null;
		if (!is_string($objectId) || $objectId === '') {
			throw new InvalidJobPayloadException('A poll close job needs "object_id".');
		}

		$this->polls->close($objectId, $this->clock->now());
	}
}
