<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both per-user block repositories must satisfy.
 *
 * The in-memory and SQL implementations are interchangeable only if they
 * agree on uniqueness, scoping, ordering, cursors and ownership.
 */
abstract class BlockRepositoryTestCase extends TestCase
{
	abstract protected function repository(): BlockRepository;

	public function testInsertAndFindByActorAreScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('b1', 'alice', 'https://remote.example/users/bob'));

		self::assertSame('b1', $repository->findByActor('alice', 'https://remote.example/users/bob')?->id);
		self::assertSame('alice', $repository->findByActor('alice', 'https://remote.example/users/bob')?->localUserId);
		self::assertNull($repository->findByActor('carol', 'https://remote.example/users/bob'));
		self::assertNull($repository->findByActor('alice', 'https://remote.example/users/mallory'));
	}

	public function testTheSameActorCannotBeBlockedTwiceByOneUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('b1', 'alice', 'https://remote.example/users/bob'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->block('b2', 'alice', 'https://remote.example/users/bob'));
	}

	public function testFindByIdReturnsTheStoredBlock(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('b1', 'alice', 'https://remote.example/users/bob'));

		self::assertSame('b1', $repository->findById('b1')?->id);
		self::assertSame('alice', $repository->findById('b1')?->localUserId);
		self::assertNull($repository->findById('missing'));
	}

	public function testAnotherUserMayBlockTheSameActor(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('b1', 'alice', 'https://remote.example/users/bob'));
		$repository->insert($this->block('b2', 'carol', 'https://remote.example/users/bob'));

		self::assertSame('b1', $repository->findByActor('alice', 'https://remote.example/users/bob')?->id);
		self::assertSame('b2', $repository->findByActor('carol', 'https://remote.example/users/bob')?->id);
		self::assertSame(1, $repository->count('alice'));
		self::assertSame(1, $repository->count('carol'));
	}

	public function testBlocksAreNewestFirstWithIdAsTieBreak(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('aaa', 'alice', 'https://a.example/users/one', '2026-11-11T10:00:00Z'));
		$repository->insert($this->block('cccc', 'alice', 'https://c.example/users/three', '2026-11-11T10:02:00Z'));
		$repository->insert($this->block('bbb', 'alice', 'https://b.example/users/two', '2026-11-11T10:02:00Z'));
		$repository->insert($this->block('other', 'carol', 'https://d.example/users/four', '2026-11-11T10:03:00Z'));

		self::assertSame(
			['cccc', 'bbb', 'aaa'],
			array_map(static fn (Block $block): string => $block->id, $repository->blocks('alice', 10)),
		);
		self::assertSame(
			['https://c.example/users/three', 'https://b.example/users/two', 'https://a.example/users/one'],
			$repository->actorIds('alice'),
		);
	}

	public function testBeforeIsExclusiveAndLimitIsApplied(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('old', 'alice', 'https://a.example/users/one', '2026-11-11T10:00:00Z'));
		$repository->insert($this->block('new', 'alice', 'https://b.example/users/two', '2026-11-11T10:05:00Z'));

		$page = $repository->blocks('alice', 10, Time::parse('2026-11-11T10:05:00Z'));

		self::assertSame(['old'], array_map(static fn (Block $block): string => $block->id, $page));

		$repository->insert($this->block('newer', 'alice', 'https://c.example/users/three', '2026-11-11T10:06:00Z'));
		self::assertSame(
			['newer', 'new'],
			array_map(static fn (Block $block): string => $block->id, $repository->blocks('alice', 2)),
		);
	}

	public function testRemoveOnlyDeletesTheOwnersBlock(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('b1', 'alice', 'https://remote.example/users/bob'));

		self::assertFalse($repository->remove('b1', 'carol'));
		self::assertNotNull($repository->findByActor('alice', 'https://remote.example/users/bob'));

		self::assertTrue($repository->remove('b1', 'alice'));
		self::assertNull($repository->findByActor('alice', 'https://remote.example/users/bob'));
		self::assertFalse($repository->remove('b1', 'alice'));
	}

	public function testCountIsScopedToTheLocalUser(): void
	{
		$repository = $this->repository();
		$repository->insert($this->block('b1', 'alice', 'https://a.example/users/one'));
		$repository->insert($this->block('b2', 'alice', 'https://b.example/users/two'));
		$repository->insert($this->block('b3', 'carol', 'https://c.example/users/three'));

		self::assertSame(2, $repository->count('alice'));
		self::assertSame(1, $repository->count('carol'));
		self::assertSame(0, $repository->count('dave'));
	}

	protected function block(
		string $id,
		string $localUserId,
		string $actorId,
		string $blockedAt = '2026-11-11T10:00:00Z',
	): Block {
		return new Block(
			$id,
			$localUserId,
			$actorId,
			Time::parse($blockedAt),
		);
	}
}
