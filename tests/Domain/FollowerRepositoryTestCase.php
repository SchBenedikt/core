<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\PdoFollowerRepository;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both follower repositories must satisfy.
 *
 * The in-memory and the SQL implementation are interchangeable only if they
 * agree on uniqueness, ordering, cursors and ownership; this case is the
 * definition of that agreement.
 */
abstract class FollowerRepositoryTestCase extends TestCase
{
	abstract protected function repository(): FollowerRepository;

	public function testInsertAndFind(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('f1', 'alice', 'https://remote.example/users/bob'));

		self::assertSame('f1', $repository->find('f1')?->id);
		self::assertSame('alice', $repository->find('f1')?->localUserId);
		self::assertNull($repository->find('unknown'));
	}

	public function testFindByActorIsScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('f1', 'alice', 'https://remote.example/users/bob'));
		$repository->insert($this->follower('f2', 'carol', 'https://remote.example/users/bob'));

		self::assertSame('f1', $repository->findByActor('alice', 'https://remote.example/users/bob')?->id);
		self::assertSame('f2', $repository->findByActor('carol', 'https://remote.example/users/bob')?->id);
		self::assertNull($repository->findByActor('dave', 'https://remote.example/users/bob'));
	}

	public function testUpdateInboxKeepsIdAndFollowedAt(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('f1', 'alice', 'https://remote.example/users/bob'));

		$repository->updateInbox(
			$this->follower('f1', 'alice', 'https://remote.example/users/bob')
				->withInbox('https://remote.example/users/bob/inbox-v2'),
		);

		$stored = $repository->find('f1');
		self::assertSame('https://remote.example/users/bob/inbox-v2', $stored?->inboxUrl);
		self::assertSame('2026-11-11T10:00:00Z', $stored?->toArray()['followed_at']);
	}

	public function testRemoveOnlyDeletesTheOwnersFollower(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('f1', 'alice', 'https://remote.example/users/bob'));

		self::assertFalse($repository->remove('f1', 'carol'));
		self::assertNotNull($repository->find('f1'));

		self::assertTrue($repository->remove('f1', 'alice'));
		self::assertNull($repository->find('f1'));
	}

	public function testFollowersAreNewestFirstWithIdAsTieBreak(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('aaa', 'alice', 'https://a.example/users/one', '2026-11-11T10:00:00Z'));
		$repository->insert($this->follower('cccc', 'alice', 'https://c.example/users/three', '2026-11-11T10:02:00Z'));
		$repository->insert($this->follower('bbb', 'alice', 'https://b.example/users/two', '2026-11-11T10:02:00Z'));
		$repository->insert($this->follower('other', 'carol', 'https://d.example/users/four', '2026-11-11T10:03:00Z'));

		self::assertSame(
			['cccc', 'bbb', 'aaa'],
			array_map(static fn (Follower $follower): string => $follower->id, $repository->followers('alice', 10)),
		);
	}

	public function testBeforeIsExclusive(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('old', 'alice', 'https://a.example/users/one', '2026-11-11T10:00:00Z'));
		$repository->insert($this->follower('new', 'alice', 'https://b.example/users/two', '2026-11-11T10:05:00Z'));

		$page = $repository->followers('alice', 10, Time::parse('2026-11-11T10:05:00Z'));

		self::assertSame(['old'], array_map(static fn (Follower $follower): string => $follower->id, $page));
	}

	public function testLimitIsApplied(): void
	{
		$repository = $this->repository();
		for ($i = 1; $i <= 5; $i++) {
			$repository->insert($this->follower(
				'f' . $i,
				'alice',
				'https://remote.example/users/' . $i,
				'2026-11-11T10:0' . $i . ':00Z',
			));
		}

		self::assertSame(
			['f5', 'f4'],
			array_map(static fn (Follower $follower): string => $follower->id, $repository->followers('alice', 2)),
		);
	}

	public function testInboxesAreDistinctAndSorted(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('f1', 'alice', 'https://b.example/users/two', '2026-11-11T10:00:00Z', 'https://b.example/inbox'));
		$repository->insert($this->follower('f2', 'alice', 'https://a.example/users/one', '2026-11-11T10:01:00Z', 'https://a.example/inbox'));
		$repository->insert($this->follower('f3', 'alice', 'https://c.example/users/three', '2026-11-11T10:02:00Z', 'https://a.example/inbox'));
		$repository->insert($this->follower('f4', 'carol', 'https://d.example/users/four', '2026-11-11T10:03:00Z', 'https://d.example/inbox'));

		self::assertSame(
			['https://a.example/inbox', 'https://b.example/inbox'],
			$repository->inboxesFor('alice'),
		);
	}

	public function testCountIsScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->follower('f1', 'alice', 'https://a.example/users/one'));
		$repository->insert($this->follower('f2', 'alice', 'https://b.example/users/two'));
		$repository->insert($this->follower('f3', 'carol', 'https://c.example/users/three'));

		self::assertSame(2, $repository->count('alice'));
		self::assertSame(1, $repository->count('carol'));
		self::assertSame(0, $repository->count('dave'));
	}

	protected function follower(
		string $id,
		string $localUserId,
		string $actorId,
		string $followedAt = '2026-11-11T10:00:00Z',
		?string $inboxUrl = null,
	): Follower {
		return new Follower(
			$id,
			$localUserId,
			$actorId,
			$inboxUrl ?? $this->inboxFor($actorId),
			Time::parse($followedAt),
		);
	}

	protected function inboxFor(string $actorId): string
	{
		return $actorId . '/inbox';
	}
}
