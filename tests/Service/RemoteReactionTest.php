<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Interaction\InMemoryReactionRepository;
use AlohaSocial\Core\Domain\Interaction\ReactionDirection;
use AlohaSocial\Core\Domain\Interaction\ReactionKind;
use AlohaSocial\Core\Domain\Post\InMemoryFavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ReactionService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\RejectingJobQueue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;

/**
 * The outbound half: a local account likes or reacts to a stored remote post.
 *
 * The remote post is always one this instance fetched, and the local account
 * must follow its author. That is the access rule: the core does not invent an
 * inbox for an actor it has no edge to, and it never resolves a new URL while
 * handling a write.
 */
final class RemoteReactionTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const OBJECT = 'https://remote.example/notes/9';

	private FrozenClock $clock;

	private InMemoryReactionRepository $reactions;

	private InMemoryFollowingRepository $following;

	private InMemoryRemotePostRepository $remotePosts;

	private InMemoryBlockRepository $blocks;

	private InMemoryJobQueue $queue;

	private ActivityFactory $activities;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->reactions = new InMemoryReactionRepository();
		$this->following = new InMemoryFollowingRepository();
		$this->remotePosts = new InMemoryRemotePostRepository();
		$this->blocks = new InMemoryBlockRepository();
		$this->queue = new InMemoryJobQueue($this->clock, new RandomIdGenerator());
		$this->activities = new ActivityFactory(new ArrayConfig([InstanceInfo::CONFIG_APP => [
			'instance.url' => 'https://social.example',
		]]));
		$this->seedFollow();
		$this->seedRemotePost();
	}

	public function testALocalFavouriteOfARemotePostQueuesASignedLike(): void
	{
		$interaction = $this->service()->likeRemote('alice', self::OBJECT);

		self::assertTrue($interaction['viewer']['favourited']);
		self::assertSame(1, $interaction['favourites_count']);
		self::assertNotNull($this->reactions->find(ReactionDirection::Out, 'alice', self::OBJECT, ReactionKind::Like, null));

		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $jobs);
		self::assertSame(JobType::FEDERATION_DELIVER, $jobs[0]->type);
		self::assertSame(self::INBOX, $jobs[0]->payload['inbox']);
		self::assertSame('Like', $jobs[0]->payload['activity']['type']);
		self::assertSame('https://social.example/@alice#main-key', $jobs[0]->payload['key_id']);
		self::assertSame(self::OBJECT, $jobs[0]->payload['activity']['object']);
		self::assertSame($this->activities->likeId('alice', self::OBJECT), $jobs[0]->payload['activity']['id']);
	}

	public function testLikingTwiceDoesNotQueueASecondLike(): void
	{
		$this->service()->likeRemote('alice', self::OBJECT);
		$this->service()->likeRemote('alice', self::OBJECT);

		self::assertCount(1, $this->queue->reserve(10, $this->clock->now()));
	}

	public function testUnlikingQueuesAnUndoThatEmbedsTheLike(): void
	{
		$service = $this->service();
		$service->likeRemote('alice', self::OBJECT);
		$this->queue->reserve(10, $this->clock->now());

		$interaction = $service->unlikeRemote('alice', self::OBJECT);

		self::assertFalse($interaction['viewer']['favourited']);
		self::assertNull($this->reactions->find(ReactionDirection::Out, 'alice', self::OBJECT, ReactionKind::Like, null));

		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $jobs);
		self::assertSame('Undo', $jobs[0]->payload['activity']['type']);
		self::assertSame('Like', $jobs[0]->payload['activity']['object']['type']);
		self::assertSame($this->activities->likeId('alice', self::OBJECT), $jobs[0]->payload['activity']['object']['id']);
	}

	public function testUnlikingAnAbsentLikeSendsNothing(): void
	{
		$interaction = $this->service()->unlikeRemote('alice', self::OBJECT);

		self::assertFalse($interaction['viewer']['favourited']);
		self::assertSame([], $this->queue->reserve(10, $this->clock->now()));
	}

	public function testAnEmojiReactionQueuesAnEmojiReactAndItsUndo(): void
	{
		$service = $this->service();
		$interaction = $service->reactToRemote('alice', self::OBJECT, '❤');

		self::assertSame([['emoji' => '❤', 'count' => 1]], $interaction['emoji_reactions']);
		self::assertSame(['❤'], $interaction['viewer']['reactions']);

		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $jobs);
		self::assertSame('EmojiReact', $jobs[0]->payload['activity']['type']);
		self::assertSame('❤', $jobs[0]->payload['activity']['content']);

		$interaction = $service->unreactFromRemote('alice', self::OBJECT, '❤');

		self::assertSame([], $interaction['emoji_reactions']);
		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $jobs);
		self::assertSame('Undo', $jobs[0]->payload['activity']['type']);
		self::assertSame('EmojiReact', $jobs[0]->payload['activity']['object']['type']);
	}

	public function testLikesAndEmojiStaySeparateInTheOutboundView(): void
	{
		$service = $this->service();
		$service->likeRemote('alice', self::OBJECT);
		$service->reactToRemote('alice', self::OBJECT, '❤');

		$interaction = $service->reactToRemote('alice', self::OBJECT, '🎉');

		self::assertSame(1, $interaction['favourites_count']);
		self::assertSame(
			[['emoji' => '❤', 'count' => 1], ['emoji' => '🎉', 'count' => 1]],
			$interaction['emoji_reactions'],
		);
	}

	public function testAnInvalidOutboundEmojiIsRefused(): void
	{
		$service = $this->service();

		foreach (['', 'nice post', ':blob:', '<b>❤</b>'] as $emoji) {
			try {
				$service->reactToRemote('alice', self::OBJECT, $emoji);
				self::fail('Expected the emoji "' . $emoji . '" to be refused.');
			} catch (ReactionException $exception) {
				self::assertSame('invalid_emoji', $exception->reason);
			}
		}

		self::assertSame([], $this->queue->reserve(10, $this->clock->now()));
	}

	public function testAnUnknownRemotePostCannotBeLiked(): void
	{
		$this->expectException(ReactionException::class);
		$this->expectExceptionMessage('Remote post not found.');

		$this->service()->likeRemote('alice', 'https://remote.example/notes/unknown');
	}

	public function testADeletedRemotePostCannotBeLiked(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/create-2',
			objectId: 'https://remote.example/notes/deleted',
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'Gone',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			deletedAt: Time::parse('2026-11-11T09:30:00Z'),
		));

		$this->expectException(ReactionException::class);

		$this->service()->likeRemote('alice', 'https://remote.example/notes/deleted');
	}

	public function testAPostFromAnUnfollowedActorCannotBeLiked(): void
	{
		$service = $this->service();
		$this->following->remove('alice', self::ACTOR);

		try {
			$service->likeRemote('alice', self::OBJECT);
			self::fail('Expected an unfollowed actor to be refused.');
		} catch (ReactionException $exception) {
			self::assertSame('remote_post_not_followed', $exception->reason);
		}
	}

	public function testABlockedActorCannotBeLiked(): void
	{
		$this->blocks->insert(new Block('block-1', 'alice', self::ACTOR, Time::parse('2026-11-11T09:00:00Z')));

		try {
			$this->service()->likeRemote('alice', self::OBJECT);
			self::fail('Expected a blocked actor to be refused.');
		} catch (ReactionException $exception) {
			self::assertSame('blocked_actor', $exception->reason);
		}
	}

	public function testTheLocalLikeSurvivesADeliveryQueueFailure(): void
	{
		$service = $this->service(new RejectingJobQueue());

		try {
			$service->likeRemote('alice', self::OBJECT);
			self::fail('Expected the queue failure to surface.');
		} catch (RuntimeException $exception) {
			self::assertSame('The delivery queue is unavailable.', $exception->getMessage());
		}

		self::assertNotNull(
			$this->reactions->find(ReactionDirection::Out, 'alice', self::OBJECT, ReactionKind::Like, null),
			'The local like must not depend on remote delivery.',
		);
	}

	public function testTheLikeIdIsStableAcrossARemoveAndRetry(): void
	{
		$service = $this->service();
		$first = $this->activities->likeId('alice', self::OBJECT);

		$service->likeRemote('alice', self::OBJECT);
		$service->unlikeRemote('alice', self::OBJECT);
		$service->likeRemote('alice', self::OBJECT);

		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(3, $jobs);

		$likeActivities = [];
		foreach ($jobs as $job) {
			$activity = $job->payload['activity'];
			$likeActivities[] = $activity['type'] === 'Like' ? $activity['id'] : $activity['object']['id'];
		}

		self::assertSame([$first, $first, $first], $likeActivities);
	}

	public function testTheRemotePostReadNeedsAnAcceptedFollow(): void
	{
		$this->following->remove('alice', self::ACTOR);
		$this->following->insert(new FollowedActor(
			id: 'follow-2',
			localUserId: 'alice',
			actorId: self::ACTOR,
			inboxUrl: self::INBOX,
			followedAt: Time::parse('2026-11-11T09:00:00Z'),
			state: \AlohaSocial\Core\Domain\Federation\FollowState::Pending,
		));

		$this->expectException(ReactionException::class);

		$this->service()->remotePostFor('alice', self::OBJECT);
	}

	private function service(?JobQueue $queue = null): ReactionService
	{
		return new ReactionService(
			posts: new InMemoryPostRepository(),
			favourites: new InMemoryFavouriteRepository(),
			reactions: $this->reactions,
			followers: new InMemoryFollowerRepository(),
			following: $this->following,
			blocks: $this->blocks,
			activities: $this->activities,
			queue: $queue ?? $this->queue,
			clock: $this->clock,
			mutes: new InMemoryMuteRepository(),
			remotePosts: $this->remotePosts,
		);
	}

	private function seedFollow(): void
	{
		$this->following->insert(new FollowedActor(
			id: 'follow-1',
			localUserId: 'alice',
			actorId: self::ACTOR,
			inboxUrl: self::INBOX,
			followedAt: Time::parse('2026-11-11T09:00:00Z'),
		));
	}

	private function seedRemotePost(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/create-1',
			objectId: self::OBJECT,
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'A remote post',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
		));
	}
}
