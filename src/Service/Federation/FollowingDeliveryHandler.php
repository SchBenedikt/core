<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;

use function is_array;
use function is_string;

/**
 * Runs one queued Follow or Undo for an outgoing follow.
 *
 * A Follow job re-checks the row before every attempt: if the local account
 * unfollowed, the row is gone and the stale job completes without sending a
 * Follow after the Undo. A failed HTTP delivery throws, so the queue retries
 * the same activity and the same inbox. An Undo job has no row by design; the
 * row is removed only after the queue accepted the job.
 */
final class FollowingDeliveryHandler implements JobHandler
{
	public function __construct(
		private readonly FollowingRepository $following,
		private readonly FederationDeliveryHandler $delivery,
	) {
	}

	public function handle(Job $job): void
	{
		$operation = $job->payload['operation'] ?? null;
		$followId = $job->payload['follow_id'] ?? null;
		$inbox = $job->payload['inbox'] ?? null;
		$activity = $job->payload['activity'] ?? null;
		$keyId = $job->payload['key_id'] ?? null;

		if (
			!is_string($operation)
			|| !is_string($followId)
			|| !is_string($inbox)
			|| !is_array($activity)
			|| !is_string($keyId)
		) {
			throw new InvalidJobPayloadException(
				'A following job needs "operation", "follow_id", "inbox", "activity" and "key_id".',
			);
		}

		if ($operation === 'follow') {
			$stored = $this->following->findById($followId);
			if ($stored === null || $stored->state !== FollowState::Pending) {
				// Unfollowed, accepted already through another path, or a
				// duplicate delivery. The intended state is already true.
				return;
			}
		} elseif ($operation !== 'undo') {
			throw new InvalidJobPayloadException('Unknown following operation "' . $operation . '".');
		}

		$this->delivery->deliver($inbox, $activity, $keyId);
	}
}
