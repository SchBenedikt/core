<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Story\InMemoryStoryRepository;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Image\ImageProcessor;
use AlohaSocial\Core\Platform\Image\ProcessedImage;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\Story\StoryAccess;
use AlohaSocial\Core\Service\Story\StoryDisabledException;
use AlohaSocial\Core\Service\Story\StoryForbiddenException;
use AlohaSocial\Core\Service\Story\StoryNotFoundException;
use AlohaSocial\Core\Service\Story\StoryService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\MutableClock;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoryServiceTest extends TestCase
{
	private MutableClock $clock;

	private InMemoryStoryRepository $repository;

	private InMemoryFollowerRepository $followers;

	private InMemoryFollowingRepository $following;

	private InMemoryJobQueue $queue;

	private InMemoryBlobStore $blobs;

	private StoryService $service;

	protected function setUp(): void
	{
		$this->clock = new MutableClock('2026-11-11T10:00:00Z');
		$this->repository = new InMemoryStoryRepository();
		$this->followers = new InMemoryFollowerRepository();
		$this->following = new InMemoryFollowingRepository();
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));
		$this->blobs = new InMemoryBlobStore();
		$this->blobs->put('media/a1.jpg', 'story-bytes');
		$this->blobs->put('media/a1-thumb.jpg', 'thumb');

		$config = new ArrayConfig(['social' => [
			'instance.url' => 'https://social.example',
			'stories.enabled' => 'true',
			'stories.ttl_hours' => '24',
		]]);
		$ids = new SequenceIdGenerator();
		$attachments = new InMemoryAttachmentRepository();
		$attachments->insert(new Attachment(
			'a1',
			'bob',
			'media/a1.jpg',
			'media/a1-thumb.jpg',
			'image/jpeg',
			100,
			100,
			11,
			'a cat',
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$media = new MediaService(
			$attachments,
			new InMemoryPostRepository(),
			$this->blobs,
			new class implements ImageProcessor {
				public function thumbnail(string $bytes, int $maxWidth, int $maxHeight): ProcessedImage
				{
					throw new RuntimeException('not used');
				}
			},
			$config,
			$this->clock,
			$ids,
		);
		$activities = new ActivityFactory($config);
		$this->service = new StoryService(
			$config,
			$this->repository,
			new StoryAccess($this->followers, $this->following, $activities, $this->clock),
			$media,
			$this->followers,
			$this->following,
			$this->queue,
			$activities,
			$this->clock,
			$ids,
		);
	}

	public function testCreatePublishesAFollowersOnlyStoryWithAnExpiryJob(): void
	{
		$this->acceptFollower('alice');
		$this->followRemoteInbox('dave');

		$story = $this->service->create('bob', 'a1', ' hello ');

		self::assertSame('bob', $story->ownerId);
		self::assertSame('hello', $story->caption);
		self::assertSame('2026-11-12T10:00:00Z', Time::format($story->expiresAt));
		self::assertSame(
			'https://social.example/stories/' . $story->id,
			$story->objectId,
		);

		$presented = $this->service->show('bob', $story->id);
		self::assertSame('image', $presented['media']['kind']);
		self::assertSame('/api/v1/stories/' . $story->id . '/media', $presented['media']['url']);

		$jobs = $this->queue->reserve(10, $this->clock->now()->modify('+25 hours'));
		$types = array_map(static fn ($job): string => $job->type, $jobs);
		self::assertContains(JobType::STORY_EXPIRE, $types);
		self::assertContains(JobType::FEDERATION_DELIVER, $types);
	}

	public function testOnlyTheOwnerAndAcceptedFollowersCanRead(): void
	{
		$this->acceptFollower('alice');
		$story = $this->service->create('bob', 'a1');

		self::assertSame($story->id, $this->service->show('alice', $story->id)['id']);
		self::assertCount(1, $this->service->feed('alice'));

		$this->expectException(StoryNotFoundException::class);
		$this->service->show('carol', $story->id);
	}

	public function testAViewerCannotReadTheReceiptList(): void
	{
		$this->acceptFollower('alice');
		$story = $this->service->create('bob', 'a1');
		$this->service->markView('alice', $story->id);

		$viewers = $this->service->viewers('bob', $story->id);
		self::assertCount(1, $viewers);
		self::assertSame('alice', $viewers[0]['viewer_id']);

		$this->expectException(StoryForbiddenException::class);
		$this->service->viewers('alice', $story->id);
	}

	public function testReactionsAndRepliesStayPrivate(): void
	{
		$this->acceptFollower('alice');
		$story = $this->service->create('bob', 'a1');

		$reaction = $this->service->react('alice', $story->id, '❤️');
		self::assertSame('❤️', $reaction['emoji']);
		self::assertCount(1, $this->service->reactions('alice', $story->id));

		$reply = $this->service->reply('alice', $story->id, 'nice');
		self::assertSame('nice', $reply['content']);
		self::assertCount(1, $this->service->replies('bob', $story->id));
	}

	public function testMediaIsReadOnlyThroughTheStoryAccessCheck(): void
	{
		$this->acceptFollower('alice');
		$story = $this->service->create('bob', 'a1');

		self::assertSame('story-bytes', $this->service->readMedia('alice', $story->id)['bytes']);

		$this->expectException(StoryNotFoundException::class);
		$this->service->readMedia('carol', $story->id);
	}

	public function testExpiryRemovesTheStoryAndItsLocalMedia(): void
	{
		$story = $this->service->create('bob', 'a1');
		$this->clock->advance(86400);

		self::assertSame(1, $this->service->expireDue());
		self::assertTrue($this->repository->find($story->id)?->isDeleted() ?? false);
		self::assertFalse($this->blobs->exists('media/a1.jpg'));

		$this->expectException(StoryNotFoundException::class);
		$this->service->show('bob', $story->id);
	}

	public function testDisabledStoriesRejectLocalOperations(): void
	{
		$disabled = new StoryService(
			new ArrayConfig(['social' => ['instance.url' => 'https://social.example', 'stories.enabled' => 'false']]),
			$this->repository,
			new StoryAccess($this->followers, $this->following, new ActivityFactory(new ArrayConfig(['social' => ['instance.url' => 'https://social.example']])), $this->clock),
			null,
			$this->followers,
			$this->following,
			$this->queue,
			new ActivityFactory(new ArrayConfig(['social' => ['instance.url' => 'https://social.example']])),
			$this->clock,
			new SequenceIdGenerator(),
		);

		$this->expectException(StoryDisabledException::class);
		$disabled->create('bob', 'a1');
	}

	public function testAnInboundAddFromAFollowedActorIsStoredAndClamped(): void
	{
		$this->followRemote('carol');

		$story = $this->service->receiveAdd('https://remote.example/users/carol', [
			'type' => 'Add',
			'object' => [
				'id' => 'https://remote.example/stories/1',
				'type' => 'Story',
				'content' => 'remote',
				'published' => '2026-11-11T09:00:00Z',
				'endTime' => '2026-11-20T09:00:00Z',
				'to' => ['https://remote.example/users/carol/followers'],
				'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.example/media/1.jpg']],
			],
		]);

		self::assertNotNull($story);
		self::assertFalse($story->local);
		self::assertSame('https://remote.example/media/1.jpg', $story->remoteMediaUrl);
		self::assertSame('2026-11-12T10:00:00Z', Time::format($story->expiresAt));
		self::assertSame($story->id, $this->service->show('bob', $story->id)['id']);
	}

	public function testAnInboundAddIsRejectedWhenItIsPublicOrNotFollowed(): void
	{
		$this->followRemote('carol');
		$activity = [
			'type' => 'Add',
			'object' => [
				'id' => 'https://remote.example/stories/1',
				'type' => 'Story',
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
				'attachment' => [['url' => 'https://remote.example/media/1.jpg']],
			],
		];

		try {
			$this->service->receiveAdd('https://remote.example/users/carol', $activity);
			self::fail('A public story must be rejected.');
		} catch (InboxRejectedException $exception) {
			self::assertSame('story_public', $exception->reason);
		}

		$this->expectException(InboxRejectedException::class);
		$this->service->receiveAdd('https://remote.example/users/dave', $activity);
	}

	public function testPixelfedAddAndDeleteFixturesRoundTrip(): void
	{
		$this->followRemote('carol');
		$add = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/pixelfed-story-add.json'), true, 64, JSON_THROW_ON_ERROR);
		$delete = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/pixelfed-story-delete.json'), true, 64, JSON_THROW_ON_ERROR);

		$story = $this->service->receiveAdd('https://remote.example/users/carol', $add);
		self::assertNotNull($story);
		self::assertSame('https://remote.example/stories/1', $story->objectId);

		$this->service->receiveDelete('https://remote.example/users/carol', $delete['object']['id']);
		self::assertTrue($this->repository->find($story->id)?->isDeleted() ?? false);
	}

	public function testAnInboundDeleteTombstonesAReplayedAdd(): void
	{
		$this->followRemote('carol');
		$this->service->receiveDelete('https://remote.example/users/carol', 'https://remote.example/stories/1');

		self::assertTrue($this->repository->isTombstoned('https://remote.example/stories/1', 'https://remote.example/users/carol'));

		$story = $this->service->receiveAdd('https://remote.example/users/carol', [
			'type' => 'Add',
			'object' => [
				'id' => 'https://remote.example/stories/1',
				'type' => 'Story',
				'to' => ['https://remote.example/users/carol/followers'],
				'attachment' => [['url' => 'https://remote.example/media/1.jpg']],
			],
		]);

		self::assertNull($story);
	}

	public function testDeleteIsOwnerOnlyAndFederatesOnce(): void
	{
		$this->acceptFollower('alice');
		$this->followRemoteInbox('dave');
		$story = $this->service->create('bob', 'a1');
		$this->queue->reserve(10, $this->clock->now());

		$this->service->delete('bob', $story->id);

		$jobs = $this->queue->reserve(10, $this->clock->now());
		self::assertNotEmpty($jobs);
		self::assertSame('Delete', $jobs[0]->payload['activity']['type']);

		$this->expectException(StoryNotFoundException::class);
		$this->service->delete('alice', $story->id);
	}

	private function acceptFollower(string $localUserId): void
	{
		$this->followers->insert(new Follower(
			'f-' . $localUserId,
			'bob',
			'https://social.example/@' . $localUserId,
			'https://social.example/@' . $localUserId . '/inbox',
			Time::parse('2026-11-01T10:00:00Z'),
			state: FollowState::Accepted,
			local: true,
		));
	}

	private function followRemoteInbox(string $localUserId): void
	{
		$this->followers->insert(new Follower(
			'f-remote-' . $localUserId,
			'bob',
			'https://remote.example/users/' . $localUserId,
			'https://remote.example/users/' . $localUserId . '/inbox',
			Time::parse('2026-11-01T10:00:00Z'),
			state: FollowState::Accepted,
		));
	}

	private function followRemote(string $name): void
	{
		$this->following->insert(new FollowedActor(
			'g-' . $name,
			'bob',
			'https://remote.example/users/' . $name,
			'https://remote.example/users/' . $name . '/inbox',
			Time::parse('2026-11-01T10:00:00Z'),
			FollowState::Accepted,
		));
	}
}
