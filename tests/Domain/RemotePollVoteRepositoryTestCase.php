<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\RemotePollVote;
use AlohaSocial\Core\Domain\Federation\RemotePollVoteRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

abstract class RemotePollVoteRepositoryTestCase extends TestCase
{
	abstract protected function repository(): RemotePollVoteRepository;

	public function testAVoteRoundTripsAndIsCountedPerPosition(): void
	{
		$repository = $this->repository();
		$repository->insert(new RemotePollVote('poll-1', 'alice', [0, 2], Time::parse('2026-11-11T10:00:00Z')));
		$repository->insert(new RemotePollVote('poll-1', 'bob', [0], Time::parse('2026-11-11T10:01:00Z')));

		$found = $repository->find('poll-1', 'alice');
		self::assertNotNull($found);
		self::assertSame([0, 2], $found->positions);
		self::assertFalse($found->queued);
		self::assertSame([0 => 2, 2 => 1], $repository->countsFor('poll-1'));
		self::assertNull($repository->find('poll-1', 'carol'));
	}

	public function testAVoterCannotVoteTwice(): void
	{
		$repository = $this->repository();
		$repository->insert(new RemotePollVote('poll-1', 'alice', [0], Time::parse('2026-11-11T10:00:00Z')));

		$this->expectException(DatabaseException::class);

		$repository->insert(new RemotePollVote('poll-1', 'alice', [1], Time::parse('2026-11-11T10:01:00Z')));
	}

	public function testMarkQueuedIsVisible(): void
	{
		$repository = $this->repository();
		$repository->insert(new RemotePollVote('poll-1', 'alice', [1], Time::parse('2026-11-11T10:00:00Z')));

		$repository->markQueued('poll-1', 'alice');

		self::assertTrue($repository->find('poll-1', 'alice')?->queued);
	}
}
