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
use AlohaSocial\Core\Platform\Db\DatabaseException;
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

	public function testInsertRejectsADuplicateId(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
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

	public function testBeforeIdKeepsSameSecondPostsOlderThanTheBoundary(): void
	{
		$repository = $this->repository();
		$time = Time::parse('2026-11-11T10:02:00Z');
		$repository->insert($this->post('aaa', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('bbb', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('ccc', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));

		$page = $repository->publicTimeline(10, $time, 'bbb');

		self::assertSame(['aaa', 'ccc'], array_map(static fn (Post $post): string => $post->id, $page));
	}

	public function testAfterIdReturnsOnlyNewerPosts(): void
	{
		$repository = $this->repository();
		$time = Time::parse('2026-11-11T10:02:00Z');
		$repository->insert($this->post('aaa', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('bbb', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('ccc', 'alice', Visibility::Public, '2026-11-11T10:04:00Z'));

		$page = $repository->publicTimeline(10, null, null, $time, 'aaa');

		self::assertSame(['ccc', 'bbb'], array_map(static fn (Post $post): string => $post->id, $page));
	}

	public function testAuthorTimelineUsesThePublicIdCursor(): void
	{
		$repository = $this->repository();
		$time = Time::parse('2026-11-11T10:02:00Z');
		$repository->insert($this->post('a1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('a2', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('a3', 'alice', Visibility::Followers, '2026-11-11T10:03:00Z'));
		$repository->insert($this->post('b1', 'bob', Visibility::Public, '2026-11-11T10:02:00Z'));

		self::assertSame(
			['a2', 'a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorTimeline('alice', 10)),
		);
		self::assertSame(
			['a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorTimeline('alice', 10, $time, 'a2')),
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

	public function testAuthorPostsPageIsPublicOnlyAndPaginated(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('a1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('a2', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('a3', 'alice', Visibility::Unlisted, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('a4', 'alice', Visibility::Public, '2026-11-11T10:03:00Z'));
		$repository->insert($this->post('a5', 'alice', Visibility::Public, '2026-11-11T10:04:00Z'));
		$repository->insert($this->post('b1', 'bob', Visibility::Public, '2026-11-11T10:05:00Z'));

		self::assertSame(
			['a5', 'a4'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorPostsPage('alice', 2, 0)),
		);
		self::assertSame(
			['a3', 'a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorPostsPage('alice', 2, 2)),
		);
		self::assertSame([], $repository->authorPostsPage('alice', 2, 9));
		self::assertSame(4, $repository->authorPostCount('alice'));
		self::assertSame(1, $repository->authorPostCount('bob'));
		self::assertSame(0, $repository->authorPostCount('nobody'));
	}

	public function testOwnTimelineIncludesNonPublicPostsButNothingFromOthers(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('own-public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('own-private', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('other-private', 'bob', Visibility::Followers, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('other-public', 'bob', Visibility::Public, '2026-11-11T10:03:00Z'));

		self::assertSame(
			['own-private', 'own-public'],
			array_map(
				static fn (Post $post): string => $post->id,
				$repository->ownTimeline('alice', 10),
			),
		);
		self::assertSame([], $repository->ownTimeline('nobody', 10));
	}

	public function testTimelineForAuthorsIncludesTheirLivePostsOnly(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('a-public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('a-private', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('b-private', 'bob', Visibility::Followers, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('c-public', 'carol', Visibility::Public, '2026-11-11T10:03:00Z'));
		$repository->insert($this->post('a-gone', 'alice', Visibility::Public, '2026-11-11T10:04:00Z'));
		$repository->softDelete('a-gone', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['b-private', 'a-private', 'a-public'],
			array_map(
				static fn (Post $post): string => $post->id,
				$repository->timelineForAuthors(['alice', 'bob'], 10),
			),
		);
		self::assertSame(
			['a-public'],
			array_map(
				static fn (Post $post): string => $post->id,
				$repository->timelineForAuthors(['alice', 'bob'], 10, Time::parse('2026-11-11T10:01:00Z')),
			),
		);
		self::assertSame([], $repository->timelineForAuthors([], 10));
	}

	public function testSoftDeleteHidesThePostButKeepsTheRow(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));

		$repository->softDelete('p1', Time::parse('2026-11-11T11:00:00Z'));

		self::assertTrue($repository->find('p1')?->isDeleted());
		self::assertSame([], $repository->publicTimeline(10));
	}

	public function testAttachmentIdsAreStoredInOrderAndLoadedBack(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post(
			'p1',
			'alice',
			Visibility::Public,
			'2026-11-11T10:00:00Z',
			attachmentIds: ['a2', 'a1'],
		));

		self::assertSame(['a2', 'a1'], $repository->find('p1')?->attachmentIds);
		self::assertSame(['p1' => ['a2', 'a1']], $repository->attachmentIdsFor(['p1']));
		self::assertSame([], $repository->attachmentIdsFor(['unknown']));
	}

	public function testAttachmentLinksAreVisibleIncludingSoftDeletedPosts(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post(
			'p1',
			'alice',
			Visibility::Public,
			'2026-11-11T10:00:00Z',
			attachmentIds: ['a1'],
		));

		self::assertTrue($repository->isAttachmentLinked('a1'));
		self::assertFalse($repository->isAttachmentLinked('a2'));

		$repository->softDelete('p1', Time::parse('2026-11-11T11:00:00Z'));

		// The link row survives the tombstone, so media deletion must still
		// refuse rather than leave a dangling reference.
		self::assertTrue($repository->isAttachmentLinked('a1'));
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
		array $attachmentIds = [],
	): Post {
		return new Post(
			$id,
			$author,
			'content of ' . $id,
			$visibility,
			Time::parse($publishedAt),
			$contentWarning,
			null,
			$attachmentIds,
		);
	}
}
