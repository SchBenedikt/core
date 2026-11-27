<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryInboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Interaction\InMemoryReactionRepository;
use AlohaSocial\Core\Domain\Interaction\ReactionDirection;
use AlohaSocial\Core\Domain\Interaction\ReactionKind;
use AlohaSocial\Core\Domain\Interaction\Reaction;
use AlohaSocial\Core\Domain\Post\InMemoryFavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
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

	private InMemoryPostRepository $posts;

	private InMemoryFollowerRepository $followers;

	private InMemoryFollowingRepository $following;

	private InMemoryRemotePostRepository $remotePosts;

	private InMemoryBlockRepository $blocks;

	private InMemoryJobQueue $queue;

	private ActivityFactory $activities;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->reactions = new InMemoryReactionRepository();
		$this->posts = new InMemoryPostRepository();
		$this->followers = new InMemoryFollowerRepository();
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

	public function testRemoteBoostIsIdempotentAndUndoEmbedsTheAnnounce(): void
	{
		$service = $this->service();
		$first = $service->boostRemote('alice', self::OBJECT);
		$second = $service->boostRemote('alice', self::OBJECT);
		self::assertTrue($first['viewer']['reblogged']);
		self::assertTrue($second['viewer']['reblogged']);
		self::assertSame(1, $second['reblogs_count']);
		self::assertNotNull($this->reactions->find(ReactionDirection::Out, 'alice', self::OBJECT, ReactionKind::Boost, null));

		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $jobs);
		self::assertSame('Announce', $jobs[0]->payload['activity']['type']);
		self::assertSame(self::OBJECT, $jobs[0]->payload['activity']['object']);

		$undone = $service->unboostRemote('alice', self::OBJECT);
		self::assertFalse($undone['viewer']['reblogged']);
		$undoJobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $undoJobs);
		self::assertSame('Undo', $undoJobs[0]->payload['activity']['type']);
		self::assertSame('Announce', $undoJobs[0]->payload['activity']['object']['type']);
		self::assertSame($jobs[0]->payload['activity']['id'], $undoJobs[0]->payload['activity']['object']['id']);

		$this->clock->advance(1);
		$service->boostRemote('alice', self::OBJECT);
		$reboostJobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $reboostJobs);
		self::assertNotSame($jobs[0]->payload['activity']['id'], $reboostJobs[0]->payload['activity']['id']);
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

	public function testAPublicPostFromAnUnfollowedActorCanBeLiked(): void
	{
		$service = $this->service(remoteActors: $this->fetcher());
		$this->following->remove('alice', self::ACTOR);

		$interaction = $service->likeRemote('alice', self::OBJECT);

		self::assertTrue($interaction['viewer']['favourited']);
		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertCount(1, $jobs);
		self::assertSame('Like', $jobs[0]->payload['activity']['type']);
		self::assertSame('https://remote.example/users/bob/inbox', $jobs[0]->payload['inbox']);
	}

	public function testAFollowersOnlyPostFromAnUnfollowedActorIsRefused(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/create-followers',
			objectId: 'https://remote.example/notes/followers',
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'Followers only',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:10:00Z'),
			audience: Visibility::Followers,
		));
		$this->following->remove('alice', self::ACTOR);

		try {
			$this->service()->likeRemote('alice', 'https://remote.example/notes/followers');
			self::fail('Expected a followers-only post to be refused.');
		} catch (ReactionException $exception) {
			self::assertSame('remote_post_not_followed', $exception->reason);
		}
	}

	public function testAPendingFollowerCannotReactToAFollowersOnlyLocalPost(): void
	{
		$post = new \AlohaSocial\Core\Domain\Post\Post(
			id: 'post-followers',
			authorId: 'alice',
			content: 'Only my followers should see this.',
			visibility: Visibility::Followers,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
		);
		$this->posts->insert($post);
		$this->followers->insert(new Follower(
			id: 'follower-pending',
			localUserId: 'alice',
			actorId: self::ACTOR,
			inboxUrl: self::INBOX,
			followedAt: Time::parse('2026-11-11T09:30:00Z'),
			state: FollowState::Pending,
		));

		$actor = new RemoteActor(
			actorId: self::ACTOR,
			keyId: self::ACTOR . '#main-key',
			inboxUrl: self::INBOX,
			publicKeyPem: 'test-public-key',
		);
		$activity = [
			'id' => 'https://remote.example/activities/like-pending',
			'type' => 'Like',
			'actor' => self::ACTOR,
			'object' => 'https://social.example/api/v1/posts/post-followers',
		];

		try {
			$this->service()->receiveLike($actor, $activity);
			self::fail('A pending follower must not react to a followers-only post.');
		} catch (InboxRejectedException $exception) {
			self::assertSame('not_authorized', $exception->reason);
		}

		self::assertSame(0, $this->reactions->countsForPosts(['post-followers'])['post-followers']['likes']);
		self::assertSame([], $this->queue->reserve(10, $this->clock->now()));

		$this->followers->updateState(
			'follower-pending',
			'alice',
			FollowState::Pending,
			FollowState::Accepted,
		);
		$this->service()->receiveLike($actor, [
			...$activity,
			'id' => 'https://remote.example/activities/like-accepted',
		]);

		self::assertSame(1, $this->reactions->countsForPosts(['post-followers'])['post-followers']['likes']);
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

	public function testAnActorThatBlockedTheLocalAccountCannotReceiveNewReactions(): void
	{
		$inboundBlocks = new InMemoryInboundBlockRepository();
		$inboundBlocks->block('alice', self::ACTOR, 'https://remote.example/activities/block-1');
		$service = $this->service(inboundBlocks: $inboundBlocks);

		foreach ([
			fn () => $service->likeRemote('alice', self::OBJECT),
			fn () => $service->reactToRemote('alice', self::OBJECT, '❤'),
		] as $action) {
			try {
				$action();
				self::fail('Expected a remote actor block to refuse the interaction.');
			} catch (ReactionException $exception) {
				self::assertSame('blocked_actor', $exception->reason);
			}
		}

		self::assertSame([], $this->queue->reserve(10, $this->clock->now()));
	}

	public function testAnInboundBlockLetsTheLocalAccountRemoveAReactionWithoutSendingAnUndo(): void
	{
		$reaction = new Reaction(
			direction: ReactionDirection::Out,
			subjectId: 'alice',
			objectId: self::OBJECT,
			localPostId: null,
			kind: ReactionKind::Like,
			emoji: null,
			iconUrl: null,
			activityId: $this->activities->likeId('alice', self::OBJECT),
			createdAt: Time::parse('2026-11-11T09:30:00Z'),
		);
		$this->reactions->insert($reaction);
		$emojiReaction = new Reaction(
			direction: ReactionDirection::Out,
			subjectId: 'alice',
			objectId: self::OBJECT,
			localPostId: null,
			kind: ReactionKind::Emoji,
			emoji: '❤',
			iconUrl: null,
			activityId: $this->activities->reactionId('alice', self::OBJECT, '❤'),
			createdAt: Time::parse('2026-11-11T09:30:00Z'),
		);
		$this->reactions->insert($emojiReaction);
		$inboundBlocks = new InMemoryInboundBlockRepository();
		$inboundBlocks->block('alice', self::ACTOR, 'https://remote.example/activities/block-1');
		$this->following->remove('alice', self::ACTOR);

		$interaction = $this->service(inboundBlocks: $inboundBlocks)->unlikeRemote('alice', self::OBJECT);
		$emojiInteraction = $this->service(inboundBlocks: $inboundBlocks)->unreactFromRemote('alice', self::OBJECT, '❤');

		self::assertFalse($interaction['viewer']['favourited']);
		self::assertNull($this->reactions->find(ReactionDirection::Out, 'alice', self::OBJECT, ReactionKind::Like, null));
		self::assertSame([], $emojiInteraction['viewer']['reactions']);
		self::assertNull($this->reactions->find(ReactionDirection::Out, 'alice', self::OBJECT, ReactionKind::Emoji, '❤'));
		self::assertSame([], $this->queue->reserve(10, $this->clock->now()));
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

	public function testAPublicRemotePostReadDoesNotNeedAFollow(): void
	{
		$this->following->remove('alice', self::ACTOR);

		$post = $this->service()->remotePostFor('alice', self::OBJECT);

		self::assertSame(self::OBJECT, $post->objectId);
	}

	public function testAFollowersOnlyRemotePostReadNeedsAnAcceptedFollow(): void
	{
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/create-followers',
			objectId: 'https://remote.example/notes/followers',
			actorId: self::ACTOR,
			kind: RemotePostKind::Post,
			content: 'Followers only',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:10:00Z'),
			audience: Visibility::Followers,
		));
		$this->following->remove('alice', self::ACTOR);
		$this->following->insert(new FollowedActor(
			id: 'follow-2',
			localUserId: 'alice',
			actorId: self::ACTOR,
			inboxUrl: self::INBOX,
			followedAt: Time::parse('2026-11-11T09:00:00Z'),
			state: \AlohaSocial\Core\Domain\Federation\FollowState::Pending,
		));

		try {
			$this->service()->remotePostFor('alice', 'https://remote.example/notes/followers');
			self::fail('Expected a followers-only post to require an accepted follow.');
		} catch (ReactionException $exception) {
			self::assertSame('remote_post_not_followed', $exception->reason);
		}
	}

	private function service(
		?JobQueue $queue = null,
		?InMemoryInboundBlockRepository $inboundBlocks = null,
		?RemoteActorFetcher $remoteActors = null,
	): ReactionService
	{
		return new ReactionService(
			posts: $this->posts,
			favourites: new InMemoryFavouriteRepository(),
			reactions: $this->reactions,
			followers: $this->followers,
			following: $this->following,
			blocks: $this->blocks,
			activities: $this->activities,
			queue: $queue ?? $this->queue,
			clock: $this->clock,
			mutes: new InMemoryMuteRepository(),
			remotePosts: $this->remotePosts,
			inboundBlocks: $inboundBlocks,
			remoteActors: $remoteActors,
		);
	}

	private function fetcher(): RemoteActorFetcher
	{
		return new class implements RemoteActorFetcher {
			public function fetch(string $actorId): RemoteActor
			{
				return new RemoteActor(
					actorId: $actorId,
					keyId: $actorId . '#main-key',
					inboxUrl: 'https://remote.example/users/bob/inbox',
					publicKeyPem: 'test-public-key',
				);
			}
		};
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
			audience: Visibility::Public,
		));
	}
}
