<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Boost;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both local boost repositories must satisfy.
 */
abstract class BoostRepositoryTestCase extends TestCase
{
	abstract protected function repository(): BoostRepository;

	public function testInsertAndLookupsAreScopedToTheUserAndPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->boost('b1', 'alice', 'p1'));

		self::assertSame('b1', $repository->find('b1')?->id);
		self::assertSame('b1', $repository->findByUserAndPost('alice', 'p1')?->id);
		self::assertNull($repository->findByUserAndPost('bob', 'p1'));
		self::assertTrue($repository->exists('alice', 'p1'));
		self::assertFalse($repository->exists('bob', 'p1'));
		self::assertSame(1, $repository->countForPost('p1'));
		self::assertSame(0, $repository->countForPost('p2'));
	}

	public function testTheSamePairCannotBeStoredTwice(): void
	{
		$repository = $this->repository();
		$repository->insert($this->boost('b1', 'alice', 'p1'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->boost('b2', 'alice', 'p1'));
	}

	public function testBatchCountsAndViewerFlagsCoverEveryRequestedPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->boost('b1', 'alice', 'p1'));
		$repository->insert($this->boost('b2', 'bob', 'p1'));
		$repository->insert($this->boost('b3', 'alice', 'p2'));

		self::assertSame(
			['p1' => 2, 'p2' => 1, 'missing' => 0],
			$repository->countsForPosts(['p1', 'p2', 'missing']),
		);
		self::assertSame(
			['p1' => true, 'p2' => true, 'missing' => false],
			$repository->existsForPosts('alice', ['p1', 'p2', 'missing']),
		);
		self::assertSame(
			['p1' => false, 'p2' => false],
			$repository->existsForPosts('carol', ['p1', 'p2']),
		);
		self::assertSame([], $repository->countsForPosts([]));
		self::assertSame([], $repository->existsForPosts('alice', []));
	}

	public function testRemoveOnlyDeletesTheOwnersPairAndIsIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->boost('b1', 'alice', 'p1'));
		$repository->insert($this->boost('b2', 'bob', 'p1'));

		self::assertFalse($repository->remove('carol', 'p1'));
		self::assertTrue($repository->exists('alice', 'p1'));

		self::assertTrue($repository->remove('alice', 'p1'));
		self::assertFalse($repository->exists('alice', 'p1'));
		self::assertTrue($repository->exists('bob', 'p1'));
		self::assertSame(1, $repository->countForPost('p1'));
		self::assertFalse($repository->remove('alice', 'p1'));
	}

	public function testTimelineForUsersPagesNewestFirstWithAnExclusiveCursor(): void
	{
		$repository = $this->repository();
		$repository->insert($this->boost('b1', 'alice', 'p1', '2026-11-11T10:00:00Z'));
		$repository->insert($this->boost('b2', 'bob', 'p2', '2026-11-11T10:01:00Z'));
		$repository->insert($this->boost('b3', 'alice', 'p3', '2026-11-11T10:02:00Z'));
		$repository->insert($this->boost('b4', 'carol', 'p4', '2026-11-11T10:03:00Z'));

		$page = $repository->timelineForUsers(['alice', 'bob'], 2);
		self::assertSame(['b3', 'b2'], array_column($page, 'id'));

		$next = $repository->timelineForUsers(
			['alice', 'bob'],
			2,
			Time::parse('2026-11-11T10:01:00Z'),
		);
		self::assertSame(['b1'], array_column($next, 'id'));
		self::assertSame([], $repository->timelineForUsers([], 2));
	}

	private function boost(
		string $id,
		string $userId,
		string $postId,
		string $createdAt = '2026-11-11T10:00:00Z',
	): Boost {
		return new Boost($id, $userId, $postId, Time::parse($createdAt));
	}
}
