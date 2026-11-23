<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Timeline\InMemoryHomeTimelineRepository;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimelineServiceTest extends TestCase
{
	private InMemoryPostRepository $repository;

	private InMemoryFollowingRepository $following;

	private TimelineService $timelines;

	protected function setUp(): void
	{
		$this->repository = new InMemoryPostRepository();
		$this->following = new InMemoryFollowingRepository();
		$this->timelines = new TimelineService(
			$this->repository,
			new InMemoryHomeTimelineRepository(
				$this->repository,
				new InMemoryRemotePostRepository(),
				$this->following,
				new InMemoryBlockRepository(),
			),
		);
	}

	#[DataProvider('limits')]
	public function testClampsTheLimit(int $requested, int $expected): void
	{
		self::assertSame($expected, $this->timelines->clamp($requested));
	}

	/**
	 * @return iterable<string, array{int, int}>
	 */
	public static function limits(): iterable
	{
		yield 'zero' => [0, 1];
		yield 'negative' => [-5, 1];
		yield 'normal' => [20, 20];
		yield 'at the ceiling' => [TimelineService::MAX_LIMIT, TimelineService::MAX_LIMIT];
		yield 'above the ceiling' => [500, TimelineService::MAX_LIMIT];
	}

	public function testRejectsAnUnparseableCursor(): void
	{
		$this->expectException(InvalidCursorException::class);

		$this->timelines->public(20, 'yesterday');
	}

	public function testCursorIsExclusive(): void
	{
		$this->repository->insert($this->post('old', '2026-11-11T10:00:00Z'));
		$this->repository->insert($this->post('new', '2026-11-11T10:05:00Z'));

		$page = $this->timelines->public(20, '2026-11-11T10:05:00Z');

		self::assertSame(['old'], array_map(static fn (Post $post): string => $post->id, $page));
	}

	public function testCursorIdKeepsTheRestOfTheSecond(): void
	{
		$this->repository->insert($this->post('aaa', '2026-11-11T10:05:00Z'));
		$this->repository->insert($this->post('bbb', '2026-11-11T10:05:00Z'));
		$this->repository->insert($this->post('ccc', '2026-11-11T10:05:00Z'));

		$page = $this->timelines->public(20, '2026-11-11T10:05:00Z', 'ccc');

		self::assertSame(['bbb', 'aaa'], array_map(static fn (Post $post): string => $post->id, $page));
	}

	public function testCursorIdWithoutATimestampIsRejected(): void
	{
		$this->expectException(InvalidCursorException::class);

		$this->timelines->public(20, null, 'post-id');
	}

	public function testAnEmptyCursorMeansTheFirstPage(): void
	{
		$this->repository->insert($this->post('p1', '2026-11-11T10:00:00Z'));

		self::assertCount(1, $this->timelines->public(20, '   '));
	}

	public function testHomeTimelineShowsOwnPostsAndNotUnfollowedPublicOnes(): void
	{
		$this->repository->insert($this->post('own-private', '2026-11-11T10:00:00Z', 'alice', Visibility::Followers));
		$this->repository->insert($this->post('other-private', '2026-11-11T10:01:00Z', 'bob', Visibility::Followers));
		$this->repository->insert($this->post('other-public', '2026-11-11T10:02:00Z', 'bob', Visibility::Public));

		$ids = array_map(static fn (object $entry): string => $entry->id, $this->timelines->home('alice'));

		self::assertSame(['own-private'], $ids);
	}

	private function post(
		string $id,
		string $publishedAt,
		string $author = 'alice',
		Visibility $visibility = Visibility::Public,
	): Post {
		return new Post($id, $author, 'content', $visibility, Time::parse($publishedAt));
	}
}
