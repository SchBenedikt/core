<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Jobs;

/**
 * The job types the core produces.
 *
 * Constants rather than raw strings: a typo in a type name would otherwise
 * turn into a job nobody handles, which only shows up as a dead letter.
 */
final class JobType
{
	public const FEDERATION_DELIVER = 'federation.deliver';

	/**
	 * Follow and Undo(Follow) for the outgoing graph. Kept separate from a
	 * generic delivery so a retry can re-check the pending edge and skip a
	 * stale Follow after an Unfollow.
	 */
	public const FEDERATION_FOLLOW = 'federation.follow';

	public const ATPROTO_PUBLISH = 'atproto.publish';

	public const ATPROTO_DELETE = 'atproto.delete';

	/**
	 * Pulls one bounded page from the configured inbound AT Protocol source.
	 * The source, cursor and records are durable; only the page limit travels
	 * in the job.
	 */
	public const ATPROTO_SYNC = 'atproto.sync';

	public const CLEANUP = 'cleanup';

	/**
	 * Continues one bounded batch of an accepted account move. The payload
	 * carries only the durable event id; progress lives on the move row.
	 */
	public const ACCOUNT_MOVE = 'account.move';

	/** Retries object deletion after a completed account erasure. */
	public const ACCOUNT_ERASURE_BLOB_RETRY = 'account.erasure.blob_retry';

	/**
	 * Publishes one due scheduled post. The payload carries the schedule id,
	 * not a copy of the content, so a reschedule is honoured.
	 */
	public const SCHEDULED_POST_PUBLISH = 'scheduled.post.publish';

	/**
	 * Marks one remote poll closed at its scheduled end. The payload carries
	 * the question object id.
	 */
	public const POLL_CLOSE = 'poll.close';

	/**
	 * Re-runs the idempotent story expiry sweep. The payload carries no
	 * story state; the expiry is durable on the story row.
	 */
	public const STORY_EXPIRE = 'story.expire';

	private function __construct()
	{
	}
}
