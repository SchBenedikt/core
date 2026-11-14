<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Interaction\Reaction;
use AlohaSocial\Core\Domain\Interaction\ReactionDirection;
use AlohaSocial\Core\Domain\Interaction\ReactionKind;
use AlohaSocial\Core\Domain\Interaction\ReactionRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The identity rules both adapters must share.
 *
 * Every case here would pass trivially with an activity-id key; they exist to
 * pin the (subject, object, kind, emoji) identity, the separation of the two
 * kinds and the direction split that keeps an inbound reaction out of the
 * outbound viewer state.
 */
abstract class ReactionRepositoryTestCase extends TestCase
{
	abstract protected function repository(): ReactionRepository;

	public function testAFreshReactionCanBeFoundByIdentity(): void
	{
		$repository = $this->repository();
		$repository->insert($this->inboundLike());

		$found = $repository->find(
			ReactionDirection::In,
			'https://remote.example/users/bob',
			'https://social.example/api/v1/posts/post-1',
			ReactionKind::Like,
			null,
		);

		self::assertNotNull($found);
		self::assertSame('post-1', $found->localPostId);
		self::assertSame('https://remote.example/activities/like-1', $found->activityId);
	}

	public function testTheSameIdentityCannotBeStoredTwice(): void
	{
		$repository = $this->repository();
		$repository->insert($this->inboundLike());

		$this->expectException(DatabaseException::class);

		$repository->insert($this->inboundLike('https://remote.example/activities/like-2'));
	}

	public function testARemovalOnlyRemovesTheMatchingIdentity(): void
	{
		$repository = $this->repository();
		$repository->insert($this->inboundLike());
		$repository->insert($this->inboundEmoji());

		self::assertTrue($repository->remove(
			ReactionDirection::In,
			'https://remote.example/users/bob',
			'https://social.example/api/v1/posts/post-1',
			ReactionKind::Like,
			null,
		));

		self::assertSame(0, $repository->countsForPosts(['post-1'])['post-1']['likes']);
		self::assertSame(['❤' => 1], $repository->countsForPosts(['post-1'])['post-1']['emojis']);
	}

	public function testRemovingAnAbsentIdentityIsANoOp(): void
	{
		self::assertFalse($this->repository()->remove(
			ReactionDirection::In,
			'https://remote.example/users/bob',
			'https://social.example/api/v1/posts/post-1',
			ReactionKind::Like,
			null,
		));
	}

	public function testAReactionCanBeResolvedByItsActivityId(): void
	{
		$this->repository()->insert($this->inboundEmoji());

		$found = $this->repository()->findByActivityId('https://remote.example/activities/react-1');

		self::assertNotNull($found);
		self::assertSame('❤', $found->emoji);
		self::assertSame('https://remote.example/emoji/blob.png', $found->iconUrl);
	}

	public function testCountsKeepLikesAndEmojiSeparatePerPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->inboundLike());
		$repository->insert($this->inboundLike(
			'https://remote.example/activities/like-2',
			'https://remote.example/users/carol',
		));
		$repository->insert($this->inboundEmoji());
		$repository->insert($this->inboundEmoji(
			'https://remote.example/activities/react-2',
			'https://remote.example/users/carol',
			'🎉',
		));

		$counts = $repository->countsForPosts(['post-1', 'post-2']);

		self::assertSame(2, $counts['post-1']['likes']);
		self::assertSame(['❤' => 1, '🎉' => 1], $counts['post-1']['emojis']);
		self::assertSame(0, $counts['post-2']['likes']);
		self::assertSame([], $counts['post-2']['emojis']);
	}

	public function testOutboundReactionsNeverAppearInLocalPostCounts(): void
	{
		$repository = $this->repository();
		$repository->insert(new Reaction(
			direction: ReactionDirection::Out,
			subjectId: 'alice',
			objectId: 'https://remote.example/notes/9',
			localPostId: null,
			kind: ReactionKind::Like,
			emoji: null,
			iconUrl: null,
			activityId: 'https://social.example/api/v1/likes/abc',
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		));

		self::assertSame(0, $repository->countsForPosts(['post-1'])['post-1']['likes']);
	}

	public function testForSubjectReturnsOnlyThatDirectionAndSubject(): void
	{
		$repository = $this->repository();
		$repository->insert(new Reaction(
			direction: ReactionDirection::Out,
			subjectId: 'alice',
			objectId: 'https://remote.example/notes/9',
			localPostId: null,
			kind: ReactionKind::Like,
			emoji: null,
			iconUrl: null,
			activityId: 'https://social.example/api/v1/likes/abc',
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		));
		$repository->insert($this->inboundLike());

		$rows = $repository->forSubject(
			ReactionDirection::Out,
			'alice',
			['https://remote.example/notes/9', 'https://remote.example/notes/10'],
		);

		self::assertCount(1, $rows['https://remote.example/notes/9']);
		self::assertSame(ReactionDirection::Out, $rows['https://remote.example/notes/9'][0]->direction);
		self::assertSame([], $rows['https://remote.example/notes/10']);
	}

	private function inboundLike(
		string $activityId = 'https://remote.example/activities/like-1',
		string $subject = 'https://remote.example/users/bob',
	): Reaction {
		return new Reaction(
			direction: ReactionDirection::In,
			subjectId: $subject,
			objectId: 'https://social.example/api/v1/posts/post-1',
			localPostId: 'post-1',
			kind: ReactionKind::Like,
			emoji: null,
			iconUrl: null,
			activityId: $activityId,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	private function inboundEmoji(
		string $activityId = 'https://remote.example/activities/react-1',
		string $subject = 'https://remote.example/users/bob',
		string $emoji = '❤',
	): Reaction {
		return new Reaction(
			direction: ReactionDirection::In,
			subjectId: $subject,
			objectId: 'https://social.example/api/v1/posts/post-1',
			localPostId: 'post-1',
			kind: ReactionKind::Emoji,
			emoji: $emoji,
			iconUrl: $emoji === '❤' ? 'https://remote.example/emoji/blob.png' : null,
			activityId: $activityId,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
