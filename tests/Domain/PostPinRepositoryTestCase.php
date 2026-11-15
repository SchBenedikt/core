<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PostPin;
use AlohaSocial\Core\Domain\Post\PostPinRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The pin contract shared by the in-memory and SQL adapters.
 */
abstract class PostPinRepositoryTestCase extends TestCase
{
	abstract protected function repository(): PostPinRepository;

	public function testPinsAreOwnerScopedAndReadInPositionOrder(): void
	{
		$repository = $this->repository();
		$repository->insert($this->pin('alice', 'p2', 2));
		$repository->insert($this->pin('alice', 'p1', 1));
		$repository->insert($this->pin('bob', 'p3', 1));

		self::assertSame(['p1', 'p2'], array_map(
			static fn (PostPin $pin): string => $pin->postId,
			$repository->forOwner('alice'),
		));
		self::assertSame(['p3'], array_map(
			static fn (PostPin $pin): string => $pin->postId,
			$repository->forOwner('bob'),
		));
		self::assertSame(2, $repository->count('alice'));
		self::assertSame(1, $repository->count('bob'));
		self::assertNull($repository->find('bob', 'p1'));
		self::assertSame(1, $repository->find('alice', 'p1')?->position);
	}

	public function testAPostCannotBePinnedTwiceOrShareAPosition(): void
	{
		$repository = $this->repository();
		$repository->insert($this->pin('alice', 'p1', 1));

		try {
			$repository->insert($this->pin('alice', 'p1', 2));
			self::fail('A duplicate post pin must be refused.');
		} catch (DatabaseException) {
			// expected
		}

		$this->expectException(DatabaseException::class);

		$repository->insert($this->pin('alice', 'p2', 1));
	}

	public function testRemovalIsIdempotentAndCanRemoveEveryOwnersReference(): void
	{
		$repository = $this->repository();
		$repository->insert($this->pin('alice', 'p1', 1));
		$repository->insert($this->pin('bob', 'p1', 1));

		self::assertTrue($repository->remove('alice', 'p1'));
		self::assertFalse($repository->remove('alice', 'p1'));
		self::assertSame(1, $repository->count('bob'));

		$repository->removeForPost('p1');

		self::assertSame(0, $repository->count('alice'));
		self::assertSame(0, $repository->count('bob'));
	}

	private function pin(string $ownerId, string $postId, int $position): PostPin
	{
		return new PostPin($ownerId, $postId, $position, Time::parse('2026-11-15T12:00:00Z'));
	}
}
