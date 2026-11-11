<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both post repositories must satisfy.
 *
 * Running the same expectations against the in-memory and the SQL
 * implementation is what keeps them interchangeable.
 */
abstract class PostRepositoryTestCase extends TestCase
{
	abstract protected function repository(): PostRepository;

	public function testInsertAndFind(): void
	{
		$repository = $this->repository();
		$post = $this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z');

		$repository->insert($post);

		self::assertSame('p1', $repository->find('p1')?->id);
		self::assertNull($repository->find('unknown'));
	}

	public function testPublicTimelineHidesNonPublicAndDeletedPosts(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('unlisted', 'alice', Visibility::Unlisted, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('followers', 'alice', Visibility::Followers, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('direct', 'alice', Visibility::Direct, '2026-11-11T10:03:00Z'));
		$repository->insert($this->post('gone', 'alice', Visibility::Public, '2026-11-11T10:04:00Z'));
		$repository->softDelete('gone', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['unlisted', 'public'],
			array_map(static fn (Post $post): string => $post->id, $repository->publicTimeline(10)),
		);
	}

	public function testTimelineIsNewestFirstWithIdAsTieBreak(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('aaa', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('cccc', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('bbb', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));

		self::assertSame(
			['cccc', 'bbb', 'aaa'],
			array_map(static fn (Post $post): string => $post->id, $repository->publicTimeline(10)),
		);
	}

	public function testBeforeIsExclusive(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('old', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('new', 'alice', Visibility::Public, '2026-11-11T10:05:00Z'));

		$page = $repository->publicTimeline(10, Time::parse('2026-11-11T10:05:00Z'));

		self::assertSame(['old'], array_map(static fn (Post $post): string => $post->id, $page));
	}

	public function testLimitIsApplied(): void
	{
		$repository = $this->repository();
		for ($i = 1; $i <= 5; $i++) {
			$repository->insert($this->post('p' . $i, 'alice', Visibility::Public, '2026-11-11T10:0' . $i . ':00Z'));
		}

		self::assertSame(
			['p5', 'p4'],
			array_map(static fn (Post $post): string => $post->id, $repository->publicTimeline(2)),
		);
	}

	public function testAuthorPostsArePublicOnlyAndNewestFirst(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('a-old', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('a-private', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('a-new', 'alice', Visibility::Unlisted, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('b-public', 'bob', Visibility::Public, '2026-11-11T10:03:00Z'));
		$repository->insert($this->post('a-gone', 'alice', Visibility::Public, '2026-11-11T10:04:00Z'));
		$repository->softDelete('a-gone', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['a-new', 'a-old'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorPosts('alice')),
		);
		self::assertSame([], $repository->authorPosts('nobody'));
	}

	public function testHomeTimelineIncludesOwnNonPublicPostsButNotThoseOfOthers(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('own-private', 'alice', Visibility::Followers, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('other-private', 'bob', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('other-public', 'bob', Visibility::Public, '2026-11-11T10:02:00Z'));

		$alice = array_map(
			static fn (Post $post): string => $post->id,
			$repository->homeTimeline('alice', 10),
		);

		sort($alice);
		self::assertSame(['other-public', 'own-private'], $alice);

		$bob = array_map(
			static fn (Post $post): string => $post->id,
			$repository->homeTimeline('bob', 10),
		);

		sort($bob);
		self::assertSame(['other-private', 'other-public'], $bob);
	}

	public function testSoftDeleteHidesThePostButKeepsTheRow(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));

		$repository->softDelete('p1', Time::parse('2026-11-11T11:00:00Z'));

		self::assertTrue($repository->find('p1')?->isDeleted());
		self::assertSame([], $repository->publicTimeline(10));
	}

	public function testSoftDeleteIsIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));

		$repository->softDelete('p1', Time::parse('2026-11-11T11:00:00Z'));
		$repository->softDelete('p1', Time::parse('2026-11-11T12:00:00Z'));

		self::assertSame('2026-11-11T11:00:00Z', $repository->find('p1')?->toArray()['deleted_at']);
	}

	protected function post(
		string $id,
		string $author,
		Visibility $visibility,
		string $publishedAt,
		?string $contentWarning = null,
	): Post {
		return new Post(
			$id,
			$author,
			'content of ' . $id,
			$visibility,
			Time::parse($publishedAt),
			$contentWarning,
		);
	}
}
