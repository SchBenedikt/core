<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\AccountMove;
use AlohaSocial\Core\Domain\Federation\AccountMoveDirection;
use AlohaSocial\Core\Domain\Federation\AccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\AccountMoveState;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

abstract class AccountMoveRepositoryTestCase extends TestCase
{
	abstract protected function repository(): AccountMoveRepository;

	public function testAnEventIdIsStoredOnceAndProgressRoundTrips(): void
	{
		$repository = $this->repository();
		$move = $this->move('https://remote.example/moves/1');
		$repository->insert($move);

		self::assertSame($move->eventId, $repository->find($move->eventId)?->eventId);
		self::assertNull($repository->find('https://remote.example/moves/none'));

		$stored = $move
			->withOutgoingProcessed('follow-1', Time::parse('2026-11-14T10:01:00Z'))
			->withFollowerProcessed('follower-1', Time::parse('2026-11-14T10:02:00Z'));
		$repository->save($stored);

		$loaded = $repository->find($move->eventId);
		self::assertSame(['follow-1'], $loaded?->processedOutgoingIds);
		self::assertSame(['follower-1'], $loaded?->processedFollowerIds);
		self::assertSame('pending', $loaded?->toArray()['state']);
	}

	public function testADuplicateEventIdIsRefused(): void
	{
		$repository = $this->repository();
		$repository->insert($this->move('https://remote.example/moves/1'));

		$this->expectException(DatabaseException::class);
		$repository->insert($this->move('https://remote.example/moves/1'));
	}

	public function testOnlyOutboundMovesAreFoundByOrigin(): void
	{
		$repository = $this->repository();
		$repository->insert($this->move('https://remote.example/moves/1', AccountMoveDirection::Inbound));
		$outbound = $this->move('https://remote.example/moves/2', AccountMoveDirection::Outbound)
			->completed(Time::parse('2026-11-14T10:05:00Z'));
		$repository->insert($outbound);

		self::assertSame('https://remote.example/moves/2', $repository->findOutboundByOrigin('https://old.example/users/alice')?->eventId);
		self::assertNull($repository->findOutboundByOrigin('https://old.example/users/nobody'));
	}

	private function move(
		string $eventId,
		AccountMoveDirection $direction = AccountMoveDirection::Inbound,
	): AccountMove {
		return new AccountMove(
			$eventId,
			'https://old.example/users/alice',
			'https://new.example/users/alice',
			'https://new.example/users/alice/inbox',
			$direction,
			AccountMoveState::Pending,
			[],
			[],
			Time::parse('2026-11-14T10:00:00Z'),
			Time::parse('2026-11-14T10:00:00Z'),
		);
	}
}
