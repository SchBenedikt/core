<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Bookmark;
use AlohaSocial\Core\Domain\Post\BookmarkRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both private bookmark repositories must satisfy.
 */
abstract class BookmarkRepositoryTestCase extends TestCase
{
	abstract protected function repository(): BookmarkRepository;

	public function testInsertFindAndOwnershipAreScoped(): void
	{
		$repository = $this->repository();
		$repository->insert($this->bookmark('alice', 'p1'));

		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertFalse($repository->exists('bob', 'p1'));
		self::assertSame('p1', $repository->find('alice', 'p1')?->postId);
		self::assertNull($repository->find('bob', 'p1'));
	}

	public function testTheSamePairCannotBeStoredTwice(): void
	{
		$repository = $this->repository();
		$repository->insert($this->bookmark('alice', 'p1'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->bookmark('alice', 'p1', '2026-11-11T10:01:00Z'));
	}

	public function testBatchFlagsCoverEveryRequestedPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->bookmark('alice', 'p1'));

		self::assertSame(
			['p1' => true, 'p2' => false, 'missing' => false],
			$repository->existsForPosts('alice', ['p1', 'p2', 'missing']),
		);
		self::assertSame([], $repository->existsForPosts('alice', []));
		self::assertSame(
			['p1' => false],
			$repository->existsForPosts('bob', ['p1']),
		);
	}

	public function testRemoveOnlyDeletesTheOwnerAndIsIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->bookmark('alice', 'p1'));

		self::assertFalse($repository->remove('bob', 'p1'));
		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertTrue($repository->remove('alice', 'p1'));
		self::assertFalse($repository->exists('alice', 'p1'));
		self::assertFalse($repository->remove('alice', 'p1'));
	}

	public function testForUserPagesNewestFirstWithTieBreakAndExclusiveCursors(): void
	{
		$repository = $this->repository();
		$repository->insert($this->bookmark('alice', 'p1', '2026-11-11T10:00:00Z'));
		$repository->insert($this->bookmark('alice', 'p3', '2026-11-11T10:01:00Z'));
		$repository->insert($this->bookmark('alice', 'p2', '2026-11-11T10:01:00Z'));
		$repository->insert($this->bookmark('bob', 'p4', '2026-11-11T10:02:00Z'));

		self::assertSame(
			['p3', 'p2'],
			array_column($repository->forUser('alice', 2), 'postId'),
		);
		self::assertSame(
			['p1'],
			array_column($repository->forUser(
				'alice',
				2,
				Time::parse('2026-11-11T10:01:00Z'),
				'p2',
			), 'postId'),
		);
		self::assertSame(
			['p3', 'p2'],
			array_column($repository->forUser(
				'alice',
				2,
				null,
				null,
				Time::parse('2026-11-11T10:00:00Z'),
				'p1',
			), 'postId'),
		);
		self::assertSame([], $repository->forUser('carol', 2));
	}

	private function bookmark(
		string $userId,
		string $postId,
		string $createdAt = '2026-11-11T10:00:00Z',
	): Bookmark {
		return new Bookmark($userId, $postId, Time::parse($createdAt));
	}
}
