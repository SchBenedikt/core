<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryInboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\MentionResolver;
use AlohaSocial\Core\Domain\Federation\MentionTarget;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Events\EventBroker;
use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\Federation\RemotePostAccess;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FixedInboxResolver;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

final class PostServiceTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryPostRepository $repository;

	private InMemoryAttachmentRepository $attachments;

	private InMemoryJobQueue $queue;

	private SequenceIdGenerator $ids;

	private ArrayConfig $config;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->repository = new InMemoryPostRepository();
		$this->attachments = new InMemoryAttachmentRepository();
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));
		$this->ids = new SequenceIdGenerator('post-');
		$this->config = new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example'],
		]);
	}

	public function testCreatesAndStoresAPost(): void
	{
		$post = $this->service()->create(new CreatePost('alice', '  hello world  '));

		self::assertSame('post-1', $post->id);
		self::assertSame('hello world', $post->content);
		self::assertSame(Visibility::Public, $post->visibility);
		self::assertSame('2026-11-11T10:00:00Z', Time::format($post->publishedAt));
		self::assertSame('post-1', $this->repository->find('post-1')?->id);
	}

	public function testPublishingAPostWritesAReplayablePublicStreamEvent(): void
	{
		$broker = $this->createMock(EventBroker::class);
		$broker->expects(self::once())
			->method('publish')
			->with(self::callback(static fn (StreamEventDraft $event): bool =>
				$event->name === 'status.created'
				&& $event->audience === StreamAudience::Public
				&& $event->actorId === 'alice'
				&& $event->recipientIds === ['alice']
				&& $event->payload === ['status_id' => 'post-1']
				&& $event->occurredAt === '2026-11-11T10:00:00Z'
			));

		$this->service(events: $broker)->create(new CreatePost('alice', 'hello'));
	}

	public function testFollowersPostStreamEventIsNotPubliclyAddressed(): void
	{
		$broker = $this->createMock(EventBroker::class);
		$broker->expects(self::once())
			->method('publish')
			->with(self::callback(static fn (StreamEventDraft $event): bool =>
				$event->audience === StreamAudience::Followers
				&& $event->recipientIds === ['alice']
			));

		$this->service(events: $broker)->create(new CreatePost('alice', 'hello', Visibility::Followers));
	}

	public function testDeletingAPostPublishesItsRemovalInTheSameAudience(): void
	{
		$published = [];
		$broker = $this->createMock(EventBroker::class);
		$broker->expects(self::exactly(2))->method('publish')->willReturnCallback(
			static function (StreamEventDraft $event) use (&$published): void { $published[] = $event; },
		);
		$service = $this->service(events: $broker);
		$post = $service->create(new CreatePost('alice', 'hello', Visibility::Followers));

		$service->delete('alice', $post->id);

		self::assertSame(['status.created', 'status.deleted'], array_column($published, 'name'));
		self::assertSame(StreamAudience::Followers, $published[1]->audience);
		self::assertSame(['status_id' => $post->id], $published[1]->payload);
	}

	public function testStoresAndNormalizesThePostLanguage(): void
	{
		$post = $this->service()->create(new CreatePost('alice', 'hello', language: ' de-DE '));

		self::assertSame('de-DE', $post->language);
		self::assertSame('de-DE', $post->toArray()['language']);
		self::assertSame('de-DE', $this->repository->find($post->id)?->language);
	}

	public function testTrimsTheContentWarningAndTreatsBlankAsNone(): void
	{
		$service = $this->service();

		self::assertSame('spoilers', $service->create(new CreatePost('alice', 'a', contentWarning: '  spoilers '))->contentWarning);
		self::assertNull($service->create(new CreatePost('alice', 'b', contentWarning: '   '))->contentWarning);
	}

	public function testRejectsEmptyContent(): void
	{
		try {
			$this->service()->create(new CreatePost('alice', '   '));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content', $exception->fields());
		}
	}

	public function testAMediaOnlyPostIsAllowed(): void
	{
		$this->attachments->insert(new Attachment(
			id: 'media-1',
			ownerId: 'alice',
			storageKey: 'blobs/media-1',
			thumbnailKey: 'blobs/media-1-thumb',
			mimeType: 'image/jpeg',
			width: 64,
			height: 64,
			byteSize: 1024,
			altText: 'A cat',
			createdAt: $this->clock->now(),
		));

		$post = $this->service()->create(new CreatePost('alice', '', attachmentIds: ['media-1']));

		self::assertSame('', $post->content);
		self::assertSame(['media-1'], $post->attachmentIds);
	}

	public function testRejectsContentAboveTheLimit(): void
	{
		try {
			$this->service()->create(new CreatePost('alice', str_repeat('a', PostService::MAX_CONTENT_LENGTH + 1)));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content', $exception->fields());
		}
	}

	public function testAcceptsContentAtTheLimit(): void
	{
		$post = $this->service()->create(new CreatePost('alice', str_repeat('a', PostService::MAX_CONTENT_LENGTH)));

		self::assertSame(PostService::MAX_CONTENT_LENGTH, mb_strlen($post->content));
	}

	public function testRejectsAContentWarningAboveTheLimit(): void
	{
		try {
			$this->service()->create(new CreatePost(
				'alice',
				'content',
				contentWarning: str_repeat('w', PostService::MAX_CONTENT_WARNING_LENGTH + 1),
			));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content_warning', $exception->fields());
		}
	}

	public function testRejectsDirectPostsWithoutARecipient(): void
	{
		try {
			$this->service()->create(new CreatePost('alice', 'secret', Visibility::Direct));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('recipient_ids', $exception->fields());
		}
	}

	public function testRejectsAnEmptyAuthor(): void
	{
		try {
			$this->service()->create(new CreatePost('   ', 'hello'));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('author', $exception->fields());
		}
	}

	public function testWithoutFollowersNothingIsQueued(): void
	{
		$this->service()->create(new CreatePost('alice', 'hello'));

		self::assertSame(0, $this->queue->pendingCount());
	}

	public function testAnUnconfiguredInstanceUrlDoesNotBreakPostingWithoutFollowers(): void
	{
		$service = $this->service(new EmptyInboxResolver(), new ArrayConfig());

		$post = $service->create(new CreatePost('alice', 'hello'));

		self::assertSame('post-1', $post->id);
		self::assertSame(0, $this->queue->pendingCount());
	}

	public function testOneJobPerInboxIsQueued(): void
	{
		$service = $this->service(new FixedInboxResolver([
			'https://remote.example/inbox',
			'https://other.example/users/bob/inbox',
		]));

		$post = $service->create(new CreatePost('alice', 'hello'));

		$jobs = $this->queue->pending();
		self::assertCount(2, $jobs);
		self::assertSame(JobType::FEDERATION_DELIVER, $jobs[0]->type);
		self::assertSame('https://remote.example/inbox', $jobs[0]->payload['inbox']);
		self::assertSame('https://social.example/@alice#main-key', $jobs[0]->payload['key_id']);
		self::assertSame('Create', $jobs[0]->payload['activity']['type']);
		self::assertSame('https://social.example/api/v1/posts/' . $post->id, $jobs[0]->payload['activity']['object']['id']);
	}

	public function testDuplicateInboxesAreDeliveredOnce(): void
	{
		$service = $this->service(new FixedInboxResolver([
			'https://remote.example/inbox',
			'https://remote.example/inbox',
		]));

		$service->create(new CreatePost('alice', 'hello'));

		self::assertCount(1, $this->queue->pending());
	}

	public function testFollowersWithoutAnInstanceUrlIsAConfigurationError(): void
	{
		$service = $this->service(new FixedInboxResolver(['https://remote.example/inbox']), new ArrayConfig());

		$this->expectException(ConfigurationException::class);

		$service->create(new CreatePost('alice', 'hello'));
	}

	public function testGetReturnsThePost(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'hello'));

		self::assertSame($post->id, $service->get($post->id)->id);
	}

	public function testGetRefusesAnUnknownPost(): void
	{
		$this->expectException(PostNotFoundException::class);

		$this->service()->get('does-not-exist');
	}

	public function testTheAuthorCanDeleteTheirPost(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'hello'));

		$service->delete('alice', $post->id);

		self::assertTrue($this->repository->find($post->id)?->isDeleted());

		$this->expectException(PostNotFoundException::class);
		$service->get($post->id);
	}

	public function testSomebodyElseCannotDeleteThePost(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'hello'));

		$this->expectException(PostAccessDeniedException::class);

		$service->delete('bob', $post->id);
	}

	public function testDeletingAnUnknownPostFails(): void
	{
		$this->expectException(PostNotFoundException::class);

		$this->service()->delete('alice', 'missing');
	}

	public function testDeletingQueuesADeleteActivity(): void
	{
		$service = $this->service(new FixedInboxResolver(['https://remote.example/inbox']));
		$post = $service->create(new CreatePost('alice', 'hello'));

		$service->delete('alice', $post->id);

		$jobs = $this->queue->pending();
		self::assertCount(2, $jobs);
		self::assertSame('Delete', $jobs[1]->payload['activity']['type']);
		self::assertSame('Tombstone', $jobs[1]->payload['activity']['object']['type']);
	}

	public function testARemoteMentionIsDeliveredAndTagged(): void
	{
		$mentions = new class implements MentionResolver {
			public function resolve(string $handle): ?MentionTarget
			{
				return $handle === 'benedikt@remote.example'
					? new MentionTarget(
						handle: $handle,
						actorId: 'https://remote.example/@benedikt',
						inboxUrl: 'https://remote.example/inbox',
					)
					: null;
			}
		};

		$post = $this->service(mentions: $mentions)->create(
			new CreatePost('alice', 'Hi @benedikt@remote.example'),
		);

		$jobs = array_values(array_filter(
			$this->queue->pending(),
			static fn ($job): bool => $job->type === JobType::FEDERATION_DELIVER,
		));

		self::assertCount(1, $jobs);
		self::assertSame('https://remote.example/inbox', $jobs[0]->payload['inbox']);
		self::assertSame('https://remote.example/@benedikt', $jobs[0]->payload['activity']['object']['tag'][0]['href']);
		self::assertContains('https://remote.example/@benedikt', $jobs[0]->payload['activity']['cc']);
		self::assertSame('public', $post->visibility->value);
	}

	public function testARemoteActorThatBlockedTheAuthorIsNotMentionDelivered(): void
	{
		$mentions = new class implements MentionResolver {
			public function resolve(string $handle): ?MentionTarget
			{
				return $handle === 'benedikt@remote.example'
					? new MentionTarget(
						handle: $handle,
						actorId: 'https://remote.example/@benedikt',
						inboxUrl: 'https://remote.example/inbox',
					)
					: null;
			}
		};
		$inboundBlocks = new InMemoryInboundBlockRepository();
		$inboundBlocks->block('alice', 'https://remote.example/@benedikt', 'https://remote.example/activities/block-1');

		$this->service(mentions: $mentions, inboundBlocks: $inboundBlocks)->create(
			new CreatePost('alice', 'Hi @benedikt@remote.example'),
		);

		$deliveries = array_values(array_filter(
			$this->queue->pending(),
			static fn ($job): bool => $job->type === JobType::FEDERATION_DELIVER,
		));
		self::assertSame([], $deliveries);
	}

	public function testRepliesToARemotePublicPost(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$remotePosts->insert($this->remoteParent());

		$post = $this->service(
			remotePosts: $remotePosts,
			remoteActors: $this->remoteActorFetcher(),
		)->create(new CreatePost('alice', 'hi there', inReplyToId: 'https://remote.example/notes/1'));

		self::assertNull($post->inReplyToId);
		self::assertSame('https://remote.example/notes/1', $post->remoteParentObjectId);
		self::assertSame('https://remote.example/users/bob', $post->remoteParentAuthorId);
		self::assertSame('https://remote.example/notes/1', $post->toArray()['remote_parent_object_id']);
		self::assertSame('https://remote.example/users/bob', $this->repository->find($post->id)?->remoteParentAuthorId);

		$jobs = $this->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('https://remote.example/inbox', $jobs[0]->payload['inbox']);
		$note = $jobs[0]->payload['activity']['object'];
		self::assertSame('https://remote.example/notes/1', $note['inReplyTo']);
		self::assertContains('https://remote.example/users/bob', $note['cc']);
	}

	public function testRemoteReplyToAnUnknownParentIsRejectedLikeAMissingLocalOne(): void
	{
		$this->expectException(ValidationException::class);

		$this->service(remotePosts: new InMemoryRemotePostRepository())
			->create(new CreatePost('alice', 'hi', inReplyToId: 'https://remote.example/notes/missing'));
	}

	public function testRemoteReplyToADeletedParentIsRejected(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$remotePosts->insert($this->remoteParent());
		$remotePosts->softDeletePostByObjectId(
			'https://remote.example/notes/1',
			'https://remote.example/users/bob',
			Time::parse('2026-11-11T09:30:00Z'),
		);

		$this->expectException(ValidationException::class);

		$this->service(remotePosts: $remotePosts)
			->create(new CreatePost('alice', 'hi', inReplyToId: 'https://remote.example/notes/1'));
	}

	public function testRemoteReplyNeedsTheSameVisibilityRuleAsARead(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$remotePosts->insert($this->remoteParent(Visibility::Followers));

		$this->expectException(ValidationException::class);

		$this->service(remotePosts: $remotePosts)
			->create(new CreatePost('alice', 'hi', inReplyToId: 'https://remote.example/notes/1'));
	}

	public function testAFollowersReplyToARemoteFollowersPostNeedsAnAcceptedFollow(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$remotePosts->insert($this->remoteParent(Visibility::Followers));
		$following = new InMemoryFollowingRepository();
		$service = $this->service(remotePosts: $remotePosts, following: $following);

		// A pending follow does not grant read access to followers-only
		// remote content, so the reply is refused exactly like a read.
		$following->insert(new FollowedActor(
			id: 'follow-1',
			localUserId: 'alice',
			actorId: 'https://remote.example/users/bob',
			inboxUrl: 'https://remote.example/users/bob/inbox',
			followedAt: Time::parse('2026-11-11T09:00:00Z'),
			state: FollowState::Pending,
		));

		try {
			$service->create(new CreatePost(
				'alice',
				'hi',
				Visibility::Followers,
				inReplyToId: 'https://remote.example/notes/1',
			));
			self::fail('A pending follow must not unlock a followers-only remote parent.');
		} catch (ValidationException) {
		}

		$following->updateState('follow-1', FollowState::Pending, FollowState::Accepted);

		$post = $service->create(new CreatePost(
			'alice',
			'hi',
			Visibility::Followers,
			inReplyToId: 'https://remote.example/notes/1',
		));

		self::assertSame('https://remote.example/notes/1', $post->remoteParentObjectId);
	}

	public function testAPublicReplyCannotWidenARemoteFollowersParent(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$remotePosts->insert($this->remoteParent(Visibility::Followers));
		$following = new InMemoryFollowingRepository();
		$following->insert(new FollowedActor(
			id: 'follow-1',
			localUserId: 'alice',
			actorId: 'https://remote.example/users/bob',
			inboxUrl: 'https://remote.example/users/bob/inbox',
			followedAt: Time::parse('2026-11-11T09:00:00Z'),
		));

		$this->expectException(ValidationException::class);

		$this->service(remotePosts: $remotePosts, following: $following)
			->create(new CreatePost('alice', 'hi', inReplyToId: 'https://remote.example/notes/1'));
	}

	public function testDirectReplyCannotTargetARemoteParent(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$remotePosts->insert($this->remoteParent());

		$this->expectException(ValidationException::class);

		$this->service(remotePosts: $remotePosts)
			->create(new CreatePost(
				'alice',
				'secret',
				Visibility::Direct,
				recipientIds: ['bob'],
				inReplyToId: 'https://remote.example/notes/1',
			));
	}

	private function service(
		?InboxResolver $inboxes = null,
		?ArrayConfig $config = null,
		?MentionResolver $mentions = null,
		?InMemoryInboundBlockRepository $inboundBlocks = null,
		?EventBroker $events = null,
		?InMemoryRemotePostRepository $remotePosts = null,
		?InMemoryFollowingRepository $following = null,
		?InMemoryRemoteActorFetcher $remoteActors = null,
	): PostService {
		return new PostService(
			$this->repository,
			$this->attachments,
			$this->queue,
			$inboxes ?? new EmptyInboxResolver(),
			new ActivityFactory($config ?? $this->config),
			$this->clock,
			$this->ids,
			mentions: $mentions,
			inboundBlocks: $inboundBlocks,
			events: $events,
			remotePosts: $remotePosts,
			remoteAccess: $remotePosts === null ? null : new RemotePostAccess(
				$following ?? new InMemoryFollowingRepository(),
				new InMemoryBlockRepository(),
				$inboundBlocks,
			),
			remoteActors: $remoteActors,
		);
	}

	private function remoteParent(Visibility $audience = Visibility::Public): RemotePost
	{
		return new RemotePost(
			activityId: 'https://remote.example/activities/1',
			objectId: 'https://remote.example/notes/1',
			actorId: 'https://remote.example/users/bob',
			kind: RemotePostKind::Post,
			content: 'remote hello',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			objectAuthorId: 'https://remote.example/users/bob',
			audience: $audience,
		);
	}

	private function remoteActorFetcher(): InMemoryRemoteActorFetcher
	{
		return (new InMemoryRemoteActorFetcher())->with(new RemoteActor(
			actorId: 'https://remote.example/users/bob',
			keyId: 'https://remote.example/users/bob#main-key',
			inboxUrl: 'https://remote.example/users/bob/inbox',
			publicKeyPem: "-----BEGIN PUBLIC KEY-----\nremote\n-----END PUBLIC KEY-----\n",
			sharedInboxUrl: 'https://remote.example/inbox',
		));
	}
}
