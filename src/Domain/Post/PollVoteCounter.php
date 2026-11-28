<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * Updates the denormalized poll counters together with a vote.
 *
 * The SQL implementation performs this in the vote repository's transaction.
 * The in-memory post store implements this hook so both assemblies expose the
 * same counts after a vote.
 */
interface PollVoteCounter
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the post or option no longer exists
	 */
	public function incrementPollVote(PollVote $vote): void;

	/**
	 * Advances the counters for one position appended to an existing vote.
	 *
	 * The option counter and the poll vote total grow by one; the voter
	 * count stays, because the voter already has a ballot.
	 *
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the post or option no longer exists
	 */
	public function addPollVotePosition(string $postId, int $position): void;
}
