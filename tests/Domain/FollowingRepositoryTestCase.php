<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both outgoing follow repositories must satisfy.
 *
 * The in-memory and SQL implementations are interchangeable only if they
 * agree on scoping, ordering and removal; this case is that agreement.
 */
abstract class FollowingRepositoryTestCase extends TestCase
{
	abstract protected function repository(): FollowingRepository;

	public function testInsertAndFind(): void
	{
		$repository = $this->repository();
		$repository->insert($this->followed('f1', 'alice', 'https://remote.example/users/bob'));

		self::assertSame('f1', $repository->find('alice', 'https://remote.example/users/bob')?->id);
		self::assertSame('alice', $repository->find('alice', 'https://remote.example/users/bob')?->localUserId);
		self::assertNull($repository->find('alice', 'https://remote.example/users/mallory'));
	}

	public function testFindIsScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->followed('f1', 'alice', 'https://remote.example/users/bob'));
		$repository->insert($this->followed('f2', 'carol', 'https://remote.example/users/bob'));

		self::assertSame('f1', $repository->find('alice', 'https://remote.example/users/bob')?->id);
		self::assertSame('f2', $repository->find('carol', 'https://remote.example/users/bob')?->id);
		self::assertNull($repository->find('dave', 'https://remote.example/users/bob'));
	}

	public function testActorIdsAreNewestFirstAndScoped(): void
	{
		$repository = $this->repository();
		$repository->insert($this->followed('aaa', 'alice', 'https://a.example/users/one', '2026-11-11T10:00:00Z'));
		$repository->insert($this->followed('cccc', 'alice', 'https://c.example/users/three', '2026-11-11T10:02:00Z'));
		$repository->insert($this->followed('bbb', 'alice', 'https://b.example/users/two', '2026-11-11T10:02:00Z'));
		$repository->insert($this->followed('other', 'carol', 'https://d.example/users/four', '2026-11-11T10:03:00Z'));

		self::assertSame(
			['https://c.example/users/three', 'https://b.example/users/two', 'https://a.example/users/one'],
			$repository->actorIds('alice'),
		);
		self::assertSame([], $repository->actorIds('dave'));
	}

	public function testRemoveIsScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->followed('f1', 'alice', 'https://remote.example/users/bob'));

		self::assertFalse($repository->remove('carol', 'https://remote.example/users/bob'));
		self::assertNotNull($repository->find('alice', 'https://remote.example/users/bob'));

		self::assertTrue($repository->remove('alice', 'https://remote.example/users/bob'));
		self::assertNull($repository->find('alice', 'https://remote.example/users/bob'));
		self::assertFalse($repository->remove('alice', 'https://remote.example/users/bob'));
	}

	public function testCountIsScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->followed('f1', 'alice', 'https://a.example/users/one'));
		$repository->insert($this->followed('f2', 'alice', 'https://b.example/users/two'));
		$repository->insert($this->followed('f3', 'carol', 'https://c.example/users/three'));

		self::assertSame(2, $repository->count('alice'));
		self::assertSame(1, $repository->count('carol'));
		self::assertSame(0, $repository->count('dave'));
	}

	protected function followed(
		string $id,
		string $localUserId,
		string $actorId,
		string $followedAt = '2026-11-11T10:00:00Z',
	): FollowedActor {
		return new FollowedActor(
			$id,
			$localUserId,
			$actorId,
			Time::parse($followedAt),
		);
	}
}
