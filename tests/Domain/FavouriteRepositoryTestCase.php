<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Favourite;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both per-user favourite repositories must satisfy.
 *
 * The in-memory and SQL implementations are interchangeable only if they
 * agree on pair uniqueness, ownership, removal and batch counts.
 */
abstract class FavouriteRepositoryTestCase extends TestCase
{
	abstract protected function repository(): FavouriteRepository;

	public function testInsertAndLookupsAreScopedToTheUserAndPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));

		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertFalse($repository->exists('bob', 'p1'));
		self::assertFalse($repository->exists('alice', 'p2'));
		self::assertSame(1, $repository->countForPost('p1'));
		self::assertSame(0, $repository->countForPost('p2'));
	}

	public function testTheSamePairCannotBeStoredTwice(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->favourite('alice', 'p1'));
	}

	public function testDifferentUsersMayFavouriteTheSamePost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));
		$repository->insert($this->favourite('bob', 'p1'));

		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertTrue($repository->exists('bob', 'p1'));
		self::assertSame(2, $repository->countForPost('p1'));
	}

	public function testOneUserMayFavouriteDifferentPosts(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));
		$repository->insert($this->favourite('alice', 'p2'));

		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertTrue($repository->exists('alice', 'p2'));
		self::assertSame(1, $repository->countForPost('p1'));
		self::assertSame(1, $repository->countForPost('p2'));
	}

	public function testCountsForPostsReturnsEveryRequestedId(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));
		$repository->insert($this->favourite('bob', 'p1'));
		$repository->insert($this->favourite('alice', 'p2'));

		self::assertSame(
			['p1' => 2, 'p2' => 1, 'missing' => 0],
			$repository->countsForPosts(['p1', 'p2', 'missing']),
		);
		self::assertSame([], $repository->countsForPosts([]));
	}

	public function testExistsForPostsReturnsEveryRequestedId(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));

		self::assertSame(
			['p1' => true, 'p2' => false],
			$repository->existsForPosts('alice', ['p1', 'p2']),
		);
		self::assertSame(
			['p1' => false, 'p2' => false],
			$repository->existsForPosts('bob', ['p1', 'p2']),
		);
		self::assertSame([], $repository->existsForPosts('alice', []));
	}

	public function testRemoveOnlyDeletesTheOwnersPairAndIsIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->favourite('alice', 'p1'));
		$repository->insert($this->favourite('bob', 'p1'));

		self::assertFalse($repository->remove('carol', 'p1'));
		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertTrue($repository->exists('bob', 'p1'));

		self::assertTrue($repository->remove('alice', 'p1'));
		self::assertFalse($repository->exists('alice', 'p1'));
		self::assertTrue($repository->exists('bob', 'p1'));
		self::assertSame(1, $repository->countForPost('p1'));

		self::assertFalse($repository->remove('alice', 'p1'));
	}

	private function favourite(string $userId, string $postId): Favourite
	{
		return new Favourite($userId, $postId, Time::parse('2026-11-11T10:00:00Z'));
	}
}
