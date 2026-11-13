<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollVote;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both poll-vote repositories must satisfy.
 *
 * Besides row uniqueness and viewer scoping, a vote must advance the stored
 * poll and option counters. A failed counter update must not leave a vote
 * behind, so the repository is the transaction boundary.
 */
abstract class PollVoteRepositoryTestCase extends TestCase
{
	abstract protected function repository(): PollVoteRepository;

	abstract protected function seedPoll(string $postId = 'p1', bool $multiple = false): void;

	abstract protected function storedPoll(string $postId = 'p1'): ?Poll;

	public function testOneVoteIsScopedToTheViewerAndAdvancesTheCounters(): void
	{
		$this->seedPoll();
		$repository = $this->repository();

		$repository->insert($this->vote('p1', 'alice', [0]));

		$vote = $repository->find('p1', 'alice');
		self::assertNotNull($vote);
		self::assertSame([0], $vote->positions);
		self::assertNull($repository->find('p1', 'bob'));

		$poll = $this->storedPoll();
		self::assertNotNull($poll);
		self::assertSame(1, $poll->votesCount);
		self::assertSame(1, $poll->votersCount);
		self::assertSame(1, $poll->options[0]->votesCount);
		self::assertSame(0, $poll->options[1]->votesCount);
	}

	public function testMultipleChoicesAndVotersAreCountedIndependently(): void
	{
		$this->seedPoll('p1', true);
		$repository = $this->repository();

		$repository->insert($this->vote('p1', 'alice', [2, 0]));
		$repository->insert($this->vote('p1', 'bob', [1]));

		self::assertSame([0, 2], $repository->find('p1', 'alice')?->positions);
		$poll = $this->storedPoll();
		self::assertNotNull($poll);
		self::assertSame(3, $poll->votesCount);
		self::assertSame(2, $poll->votersCount);
		self::assertSame(1, $poll->options[0]->votesCount);
		self::assertSame(1, $poll->options[1]->votesCount);
		self::assertSame(1, $poll->options[2]->votesCount);
	}

	public function testTheSameVoterCannotVoteTwiceAndTheCountersStayUnchanged(): void
	{
		$this->seedPoll();
		$repository = $this->repository();
		$repository->insert($this->vote('p1', 'alice', [0]));

		try {
			$repository->insert($this->vote('p1', 'alice', [1]));
			self::fail('A second vote by the same user must be rejected.');
		} catch (DatabaseException) {
			// The primary key is the idempotency boundary.
		}

		$poll = $this->storedPoll();
		self::assertNotNull($poll);
		self::assertSame(1, $poll->votesCount);
		self::assertSame(1, $poll->votersCount);
		self::assertSame([0], $repository->find('p1', 'alice')?->positions);
	}

	public function testFindForPostsReturnsOnlyTheViewersVotes(): void
	{
		$this->seedPoll('p1');
		$this->seedPoll('p2');
		$repository = $this->repository();
		$repository->insert($this->vote('p1', 'alice', [0]));
		$repository->insert($this->vote('p2', 'bob', [1]));

		$votes = $repository->findForPosts('alice', ['p1', 'p2', 'missing']);

		self::assertSame(['p1'], array_keys($votes));
		self::assertSame([0], $votes['p1']->positions);
		self::assertSame([], $repository->findForPosts('alice', []));
	}

	public function testAVoteForAnUnknownPollOrOptionIsNotStored(): void
	{
		$this->seedPoll();
		$repository = $this->repository();

		try {
			$repository->insert($this->vote('missing', 'alice', [0]));
			self::fail('A vote for a missing poll must fail.');
		} catch (DatabaseException) {
			// The counter update reports the missing poll.
		}

		try {
			$repository->insert($this->vote('p1', 'alice', [3]));
			self::fail('A vote for a missing option must fail.');
		} catch (DatabaseException) {
			// The counter update reports the missing option.
		}

		self::assertNull($repository->find('p1', 'alice'));
		self::assertSame(0, $this->storedPoll()?->votesCount);
	}

	private function vote(string $postId, string $userId, array $positions): PollVote
	{
		return new PollVote(
			postId: $postId,
			userId: $userId,
			positions: $positions,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
