<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Local votes on remote Questions.
 *
 * One row per viewer and poll. The repository deliberately does not update
 * the peer counts stored on the poll; the service adds the local rows when it
 * projects the poll.
 */
interface RemotePollVoteRepository
{
	public function find(string $objectId, string $userId): ?RemotePollVote;

	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the user already voted
	 */
	public function insert(RemotePollVote $vote): void;

	/**
	 * Marks the optimistic vote as handed to the delivery queue.
	 */
	public function markQueued(string $objectId, string $userId): void;

	/**
	 * Local vote totals per option position, for one poll.
	 *
	 * @return array<int, int> position => number of local votes
	 */
	public function countsFor(string $objectId): array;
}
