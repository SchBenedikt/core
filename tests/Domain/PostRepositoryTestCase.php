<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostCount;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
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

	abstract protected function searchRepository(): PostSearchRepository;

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

		// Unlisted is readable by link but never enumerated.
		self::assertSame(
			['public'],
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
		$repository->insert($this->post('a4', 'alice', Visibility::Direct, '2026-11-11T10:04:00Z'));
		$repository->insert($this->post('b1', 'bob', Visibility::Public, '2026-11-11T10:02:00Z'));

		self::assertSame(
			['a2', 'a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorTimeline('alice', 10)),
		);
		self::assertSame(
			['a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorTimeline('alice', 10, $time, 'a2', includeFollowers: true)),
		);
		self::assertSame(
			['a3', 'a2', 'a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorTimeline('alice', 10, includeFollowers: true)),
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
			['a-old'],
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
			['a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->authorPostsPage('alice', 2, 2)),
		);
		self::assertSame([], $repository->authorPostsPage('alice', 2, 9));
		self::assertSame(3, $repository->authorPostCount('alice'));
		self::assertSame(1, $repository->authorPostCount('bob'));
		self::assertSame(0, $repository->authorPostCount('nobody'));
	}

	public function testOwnTimelineIncludesNonPublicPostsAndAddressedDirectPosts(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('own-public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('own-private', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('other-private', 'bob', Visibility::Followers, '2026-11-11T10:02:00Z'));
		$repository->insert($this->post('other-public', 'bob', Visibility::Public, '2026-11-11T10:03:00Z'));
		$repository->insert($this->post(
			'to-alice',
			'bob',
			Visibility::Direct,
			'2026-11-11T10:04:00Z',
			recipientIds: ['alice'],
		));
		$repository->insert($this->post(
			'to-carol',
			'bob',
			Visibility::Direct,
			'2026-11-11T10:05:00Z',
			recipientIds: ['carol'],
		));

		self::assertSame(
			['to-alice', 'own-private', 'own-public'],
			array_map(
				static fn (Post $post): string => $post->id,
				$repository->ownTimeline('alice', 10),
			),
		);
		self::assertSame([], $repository->ownTimeline('nobody', 10));
	}

	public function testDirectRecipientsAndReplyTargetRoundTrip(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post(
			'parent',
			'alice',
			Visibility::Public,
			'2026-11-11T10:00:00Z',
		));
		$repository->insert($this->post(
			'reply',
			'bob',
			Visibility::Direct,
			'2026-11-11T10:01:00Z',
			recipientIds: ['carol', 'alice'],
			inReplyToId: 'parent',
		));

		$post = $repository->find('reply');
		self::assertNotNull($post);
		self::assertSame(['carol', 'alice'], $post->recipientIds);
		self::assertSame('parent', $post->inReplyToId);
		self::assertSame('parent', $post->toArray()['in_reply_to_id']);
		// Soft delete keeps the conversation metadata for moderation and
		// tombstone rendering.
		$repository->softDelete('reply', Time::parse('2026-11-11T11:00:00Z'));
		self::assertSame(['carol', 'alice'], $repository->find('reply')?->recipientIds);
		self::assertSame('parent', $repository->find('reply')?->inReplyToId);
	}

	public function testTimelineForAuthorsIncludesTheirLivePostsOnly(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('a-public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('a-private', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('a-direct', 'alice', Visibility::Direct, '2026-11-11T10:01:30Z'));
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

	public function testTimelineCursorUsesIdToContinueAcrossMatchingTimestamps(): void
	{
		$repository = $this->repository();
		$at = '2026-11-11T10:00:00Z';
		foreach (['post-a', 'post-b', 'post-c', 'post-d'] as $id) {
			$repository->insert($this->post($id, 'alice', Visibility::Public, $at));
		}

		$page = $repository->timelineForAuthors(['alice'], 2);
		self::assertSame(['post-d', 'post-c'], array_map(static fn (Post $post): string => $post->id, $page));

		$next = $repository->timelineForAuthors(
			['alice'],
			2,
			new TimelineCursor(Time::parse($at), 'post-c'),
		);
		self::assertSame(['post-b', 'post-a'], array_map(static fn (Post $post): string => $post->id, $next));
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

	public function testCountLiveIncludesEveryVisibilityAndExcludesDeletedPosts(): void
	{
		$repository = $this->repository();
		self::assertInstanceOf(PostCount::class, $repository);

		$repository->insert($this->post('public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('followers', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('gone', 'alice', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->softDelete('gone', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(2, $repository->countLive());
	}

	public function testSearchFindsOnlyLivePublicContent(): void
	{
		$repository = $this->searchRepository();
		$repository->insert($this->post('public', 'alice', Visibility::Public, '2026-11-11T10:00:00Z', content: 'Hello World'));
		$repository->insert($this->post('unlisted', 'bob', Visibility::Unlisted, '2026-11-11T10:01:00Z', content: 'hello there'));
		$repository->insert($this->post('followers', 'alice', Visibility::Followers, '2026-11-11T10:02:00Z', content: 'hello followers'));
		$repository->insert($this->post('direct', 'alice', Visibility::Direct, '2026-11-11T10:03:00Z', content: 'hello privately'));
		$repository->insert($this->post('gone', 'alice', Visibility::Public, '2026-11-11T10:04:00Z', content: 'hello again'));
		$repository->softDelete('gone', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['public'],
			array_map(static fn (Post $post): string => $post->id, $repository->search('HELLO', 10, 0)),
		);
		self::assertSame([], $repository->search('hello', 10, 0, []));
	}

	public function testSearchFiltersByAuthorsAndPagesByOffset(): void
	{
		$repository = $this->searchRepository();
		$repository->insert($this->post('a1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z', content: 'same text'));
		$repository->insert($this->post('b1', 'bob', Visibility::Public, '2026-11-11T10:01:00Z', content: 'same text'));
		$repository->insert($this->post('a2', 'alice', Visibility::Public, '2026-11-11T10:02:00Z', content: 'same text'));
		$repository->insert($this->post('c1', 'carol', Visibility::Public, '2026-11-11T10:03:00Z', content: 'same text'));

		self::assertSame(
			['a2', 'a1'],
			array_map(static fn (Post $post): string => $post->id, $repository->search('same', 10, 0, ['alice'])),
		);
		self::assertSame(
			['a2', 'b1'],
			array_map(static fn (Post $post): string => $post->id, $repository->search('same', 2, 1)),
		);
	}

	public function testSearchTreatsWildcardsAsTextAndEmptyQueriesMatchNothing(): void
	{
		$repository = $this->searchRepository();
		$repository->insert($this->post('percent', 'alice', Visibility::Public, '2026-11-11T10:00:00Z', content: '100% done'));
		$repository->insert($this->post('underscore', 'alice', Visibility::Public, '2026-11-11T10:01:00Z', content: 'under_score'));
		$repository->insert($this->post('other', 'alice', Visibility::Public, '2026-11-11T10:02:00Z', content: '100 done'));

		self::assertSame(['percent'], array_map(
			static fn (Post $post): string => $post->id,
			$repository->search('%', 10, 0),
		));
		self::assertSame(['underscore'], array_map(
			static fn (Post $post): string => $post->id,
			$repository->search('_', 10, 0),
		));
		self::assertSame([], $repository->search('   ', 10, 0));
	}

	public function testPollRoundTripsWithThePostAndSurvivesSoftDelete(): void
	{
		$repository = $this->repository();
		$poll = new Poll(
			options: [new PollOption('Yes'), new PollOption('No')],
			multiple: false,
			hideTotals: true,
			expiresAt: Time::parse('2026-11-11T11:00:00Z'),
		);
		$repository->insert($this->post(
			'p1',
			'alice',
			Visibility::Public,
			'2026-11-11T10:00:00Z',
			poll: $poll,
		));

		$stored = $repository->find('p1')?->poll;
		self::assertNotNull($stored);
		self::assertSame(['Yes', 'No'], array_map(
			static fn (PollOption $option): string => $option->title,
			$stored->options,
		));
		self::assertFalse($stored->multiple);
		self::assertTrue($stored->hideTotals);
		self::assertSame('2026-11-11T11:00:00Z', $stored->toArray()['expires_at']);

		$repository->softDelete('p1', Time::parse('2026-11-11T10:30:00Z'));
		self::assertNotNull($repository->find('p1')?->poll);
	}

	public function testRepliesArePagedAndCountedWithoutDeletedPosts(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('parent', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('r1', 'bob', Visibility::Public, '2026-11-11T10:01:00Z', inReplyToId: 'parent'));
		$repository->insert($this->post('r2', 'carol', Visibility::Public, '2026-11-11T10:02:00Z', inReplyToId: 'parent'));
		$repository->insert($this->post('other', 'bob', Visibility::Public, '2026-11-11T10:03:00Z'));

		self::assertSame(['r2', 'r1'], array_column($repository->repliesTo('parent', 10), 'id'));
		self::assertSame(['r1'], array_column($repository->repliesTo('parent', 1, Time::parse('2026-11-11T10:02:00Z')), 'id'));
		self::assertSame(2, $repository->replyCount('parent'));
		self::assertSame(
			['parent' => 2, 'other' => 0, 'missing' => 0],
			$repository->replyCountsForPosts(['parent', 'other', 'missing']),
		);
		self::assertSame([], $repository->replyCountsForPosts([]));

		$repository->softDelete('r1', Time::parse('2026-11-11T11:00:00Z'));
		self::assertSame(['r2'], array_column($repository->repliesTo('parent', 10), 'id'));
		self::assertSame(1, $repository->replyCount('parent'));
	}

	public function testHashtagTimelineIsExactPublicAndCursorAware(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('t1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z', content: 'Hello #DevOps and #php'));
		$repository->insert($this->post('t2', 'bob', Visibility::Unlisted, '2026-11-11T10:01:00Z', content: 'another #devops'));
		$repository->insert($this->post('t3', 'carol', Visibility::Followers, '2026-11-11T10:02:00Z', content: '#devops for followers'));
		$repository->insert($this->post('t4', 'dave', Visibility::Direct, '2026-11-11T10:03:00Z', content: '#devops direct', recipientIds: ['alice']));
		$repository->insert($this->post('t5', 'alice', Visibility::Public, '2026-11-11T10:04:00Z', content: '#devops gone'));
		$repository->insert($this->post('t6', 'alice', Visibility::Public, '2026-11-11T10:05:00Z', content: '#development'));
		$repository->insert($this->post('t7', 'alice', Visibility::Public, '2026-11-11T10:06:00Z', content: '#devops_extra'));
		$repository->softDelete('t5', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['t1'],
			array_column($repository->hashtagTimeline('devops', 10), 'id'),
		);
		self::assertSame(
			['t1'],
			array_column($repository->hashtagTimeline('devops', 10, Time::parse('2026-11-11T10:01:00Z'), 't2'), 'id'),
		);
		self::assertSame(['t1'], array_column($repository->hashtagTimeline('devops', 1), 'id'));
		self::assertSame([], $repository->hashtagTimeline('missing', 10));
	}

	public function testFollowedHashtagTimelineMatchesAnyTagOnceAndUsesTheSharedCursor(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('m1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z', content: '#devops'));
		$repository->insert($this->post('m2', 'alice', Visibility::Public, '2026-11-11T10:01:00Z', content: '#php'));
		$repository->insert($this->post('m3', 'alice', Visibility::Public, '2026-11-11T10:02:00Z', content: '#devops and #php'));
		$repository->insert($this->post('m4', 'bob', Visibility::Unlisted, '2026-11-11T10:03:00Z', content: '#php unlisted'));
		$repository->insert($this->post('m5', 'alice', Visibility::Followers, '2026-11-11T10:04:00Z', content: '#devops followers'));
		$repository->insert($this->post('m6', 'alice', Visibility::Public, '2026-11-11T10:05:00Z', content: '#devops_extra'));
		$repository->insert($this->post('m7', 'alice', Visibility::Public, '2026-11-11T10:06:00Z', content: '#php gone'));
		$repository->softDelete('m7', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['m3', 'm2', 'm1'],
			array_column($repository->followedHashtagTimeline(['devops', 'php'], 10), 'id'),
		);
		self::assertSame(
			['m3', 'm2'],
			array_column($repository->followedHashtagTimeline(['devops', 'php'], 2), 'id'),
		);
		self::assertSame(
			['m3', 'm2', 'm1'],
			array_column(
				$repository->followedHashtagTimeline(
					['devops', 'php'],
					10,
					Time::parse('2026-11-11T10:03:00Z'),
					'm4',
				),
				'id',
			),
		);
		self::assertSame(
			['m6'],
			array_column($repository->followedHashtagTimeline(['devops_extra'], 10), 'id'),
		);
		self::assertSame([], $repository->followedHashtagTimeline([], 10));
	}

	public function testPostsForAttachmentListsEveryLinkingPostIncludingDeletedOnes(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z', attachmentIds: ['a1']));
		$repository->insert($this->post('p2', 'alice', Visibility::Followers, '2026-11-11T10:01:00Z', attachmentIds: ['a1']));
		$repository->insert($this->post('p3', 'alice', Visibility::Public, '2026-11-11T10:02:00Z', attachmentIds: ['a2']));
		$repository->softDelete('p1', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame(
			['p2', 'p1'],
			array_map(static fn (Post $post): string => $post->id, $repository->postsForAttachment('a1')),
		);
		self::assertSame(
			['p3'],
			array_map(static fn (Post $post): string => $post->id, $repository->postsForAttachment('a2')),
		);
		self::assertSame([], $repository->postsForAttachment('missing'));
	}

	public function testSoftDeleteIsIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));

		$repository->softDelete('p1', Time::parse('2026-11-11T11:00:00Z'));
		$repository->softDelete('p1', Time::parse('2026-11-11T12:00:00Z'));

		self::assertSame('2026-11-11T11:00:00Z', $repository->find('p1')?->toArray()['deleted_at']);
	}

	public function testArchivingHidesAPostFromDiscoveryButKeepsItAddressable(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('archived', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('live', 'alice', Visibility::Public, '2026-11-11T10:01:00Z'));

		$repository->archive('archived', Time::parse('2026-11-11T11:00:00Z'));

		self::assertTrue($repository->find('archived')?->isArchived() === true);
		self::assertSame(['live'], array_map(static fn (Post $post): string => $post->id, $repository->publicTimeline(10)));
		self::assertSame(['live'], array_map(static fn (Post $post): string => $post->id, $repository->authorTimeline('alice', 10)));
		self::assertSame(['archived'], array_map(static fn (Post $post): string => $post->id, $repository->archivedTimeline('alice', 10)));
		self::assertSame(1, $repository->archivedCount('alice'));
		self::assertSame(0, $repository->archivedCount('bob'));
	}

	public function testUnarchivingRestoresThePostToLocalListings(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('p1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->archive('p1', Time::parse('2026-11-11T11:00:00Z'));

		$repository->unarchive('p1');

		self::assertNull($repository->find('p1')?->archivedAt);
		self::assertSame(['p1'], array_map(static fn (Post $post): string => $post->id, $repository->publicTimeline(10)));
		self::assertSame([], $repository->archivedTimeline('alice', 10));
	}

	public function testArchivedTimelineIsOwnerScopedOrderedAndPaginated(): void
	{
		$repository = $this->repository();
		$repository->insert($this->post('a1', 'alice', Visibility::Public, '2026-11-11T10:00:00Z'));
		$repository->insert($this->post('a2', 'alice', Visibility::Public, '2026-11-11T10:01:00Z'));
		$repository->insert($this->post('b1', 'bob', Visibility::Public, '2026-11-11T10:02:00Z'));
		$repository->archive('a1', Time::parse('2026-11-11T11:00:00Z'));
		$repository->archive('a2', Time::parse('2026-11-11T11:01:00Z'));
		$repository->archive('b1', Time::parse('2026-11-11T11:02:00Z'));

		self::assertSame(['a2', 'a1'], array_map(
			static fn (Post $post): string => $post->id,
			$repository->archivedTimeline('alice', 10),
		));
		self::assertSame(['a1'], array_map(
			static fn (Post $post): string => $post->id,
			$repository->archivedTimeline('alice', 10, Time::parse('2026-11-11T10:01:00Z')),
		));
	}

	public function testArchivingRemovesThePostFromEveryDiscoverySurfaceButKeepsTheDetailLookup(): void
	{
		$repository = $this->repository();
		$search = $this->searchRepository();
		$post = $this->post(
			'archived',
			'alice',
			Visibility::Public,
			'2026-11-11T10:00:00Z',
			content: 'DiscoverableNeedle #archivecheck',
		);
		$repository->insert($post);

		$repository->archive('archived', Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame('archived', $repository->find('archived')?->id);
		self::assertSame([], $repository->publicTimeline(10));
		self::assertSame([], $search->search('DiscoverableNeedle', 10, 0));
		self::assertSame([], $repository->authorTimeline('alice', 10));
		self::assertSame([], $repository->authorPosts('alice'));
		self::assertSame([], $repository->authorPostsPage('alice', 10, 0));
		self::assertSame(0, $repository->authorPostCount('alice'));
		self::assertSame([], $repository->timelineForAuthors(['alice'], 10));
		self::assertSame([], $repository->ownTimeline('alice', 10));
		self::assertSame([], $repository->hashtagTimeline('archivecheck', 10));
		self::assertSame([], $repository->followedHashtagTimeline(['archivecheck'], 10));
		self::assertSame(['archived'], array_map(
			static fn (Post $archived): string => $archived->id,
			$repository->archivedTimeline('alice', 10),
		));
	}

	protected function post(
		string $id,
		string $author,
		Visibility $visibility,
		string $publishedAt,
		?string $contentWarning = null,
		array $attachmentIds = [],
		?string $content = null,
		?Poll $poll = null,
		array $recipientIds = [],
		?string $inReplyToId = null,
	): Post {
		return new Post(
			$id,
			$author,
			$content ?? 'content of ' . $id,
			$visibility,
			Time::parse($publishedAt),
			$contentWarning,
			null,
			$attachmentIds,
			$poll,
			$recipientIds,
			$inReplyToId,
		);
	}
}
