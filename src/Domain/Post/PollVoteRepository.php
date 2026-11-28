<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/**
 * Storage of local poll votes.
 *
 * A poll vote is one row per voter and poll. The primary key is the
 * idempotency boundary: a second vote by the same account is rejected, while
 * the service may compare the existing positions and treat an identical retry
 * as a successful no-op.
 *
 * An insert owns the counter update as part of the same transaction. The
 * stored `social_poll` and `social_poll_option` counts are therefore never
 * advanced without a matching vote row.
 */
interface PollVoteRepository
{
	public function find(string $postId, string $userId): ?PollVote;

	/**
	 * Batched viewer lookup for timelines and search results.
	 *
	 * @param list<string> $postIds
	 *
	 * @return array<string, PollVote> only posts with a vote by this user
	 */
	public function findForPosts(string $userId, array $postIds): array;

	/**
	 * Stores one vote and increments the poll and option counters.
	 *
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the user already voted or the poll/option row is missing
	 */
	public function insert(PollVote $vote): void;

	/**
	 * Adds one distinct option position to a voter's ballot atomically.
	 *
	 * Multiple-choice polls accept one signed choice per inbound activity,
	 * so the same voter legitimately casts several ballots over time. This
	 * appends the position when the voter already has a vote: the option
	 * counter and the poll vote total advance once, the voter count does
	 * not, and a position already on the ballot is a no-op. With no
	 * existing vote it stores a fresh single-choice ballot.
	 *
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the poll or option row is missing
	 */
	public function addPosition(string $postId, string $userId, int $position, DateTimeImmutable $votedAt): PollVote;
}
