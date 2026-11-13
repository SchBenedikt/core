<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Domain\Federation\FollowerInboxResolver;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Domain\Post\InMemoryBoostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\BoostService;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

final class BoostServiceTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryPostRepository $posts;

	private InMemoryBoostRepository $boosts;

	private InMemoryNotificationRepository $notifications;

	private InMemoryJobQueue $queue;

	private PostService $postService;

	private BoostService $service;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->posts = new InMemoryPostRepository();
		$this->boosts = new InMemoryBoostRepository();
		$this->notifications = new InMemoryNotificationRepository();
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));

		$config = new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example'],
		]);
		$activities = new ActivityFactory($config);
		$followers = new InMemoryFollowerRepository();
		$followers->insert(new Follower(
			id: 'follower-1',
			localUserId: 'bob',
			actorId: 'https://remote.example/users/carol',
			inboxUrl: 'https://remote.example/users/carol/inbox',
			followedAt: Time::parse('2026-11-11T09:00:00Z'),
		));
		$notificationService = new NotificationService($this->notifications, $this->clock);

		$this->postService = new PostService(
			$this->posts,
			new InMemoryAttachmentRepository(),
			$this->queue,
			new FollowerInboxResolver($followers),
			$activities,
			$this->clock,
			new SequenceIdGenerator('post-'),
			null,
			null,
			$notificationService,
		);
		$this->service = new BoostService(
			$this->postService,
			$this->boosts,
			$this->clock,
			new SequenceIdGenerator('id-'),
			$this->queue,
			$activities,
			new FollowerInboxResolver($followers),
			$notificationService,
		);
	}

	public function testBoostStoresNotifiesAndQueuesOneAnnounce(): void
	{
		$post = $this->postService->create(new CreatePost('alice', 'boost me'));

		$result = $this->service->boost('bob', $post->id);

		self::assertTrue($result->created);
		self::assertSame('bob', $result->boost->userId);
		self::assertSame($post->id, $result->boost->postId);
		self::assertSame(1, $this->boosts->countForPost($post->id));

		$notifications = $this->notifications->listFor('alice', 10);
		self::assertCount(1, $notifications);
		self::assertSame(NotificationType::Reblog, $notifications[0]->type);
		self::assertSame('bob', $notifications[0]->actorId);
		self::assertSame($post->id, $notifications[0]->postId);

		$announces = $this->jobsOfActivityType('Announce');
		self::assertCount(1, $announces);
		self::assertSame(JobType::FEDERATION_DELIVER, $announces[0]->type);
		self::assertSame('https://remote.example/users/carol/inbox', $announces[0]->payload['inbox']);
		self::assertSame('https://social.example/@bob#main-key', $announces[0]->payload['key_id']);
		self::assertSame('https://social.example/@bob', $announces[0]->payload['activity']['actor']);
		self::assertSame('https://social.example/api/v1/posts/' . $post->id, $announces[0]->payload['activity']['object']);
		self::assertSame(
			[ActivityFactory::PUBLIC_COLLECTION],
			$announces[0]->payload['activity']['to'],
		);

		$again = $this->service->boost('bob', $post->id);
		self::assertFalse($again->created);
		self::assertSame($result->boost->id, $again->boost->id);
		self::assertCount(1, $this->jobsOfActivityType('Announce'));
	}

	public function testUnboostRemovesTheRowAndQueuesAnUndo(): void
	{
		$post = $this->postService->create(new CreatePost('alice', 'boost me'));
		$boost = $this->service->boost('bob', $post->id)->boost;

		self::assertTrue($this->service->unboost('bob', $post->id));
		self::assertSame(0, $this->boosts->countForPost($post->id));
		self::assertFalse($this->service->unboost('bob', $post->id));

		$undos = $this->jobsOfActivityType('Undo');
		self::assertCount(1, $undos);
		self::assertSame('Announce', $undos[0]->payload['activity']['object']['type']);
		self::assertNull($this->boosts->find($boost->id));
	}

	public function testOnlyPublicAndUnlistedPostsCanBeBoosted(): void
	{
		$post = $this->postService->create(new CreatePost(
			authorId: 'alice',
			content: 'followers only',
			visibility: Visibility::Followers,
		));

		try {
			$this->service->boost('alice', $post->id);
			self::fail('Expected a visibility validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('visibility', $exception->fields());
		}

		self::assertSame(0, $this->boosts->countForPost($post->id));
		self::assertSame([], $this->jobsOfActivityType('Announce'));
	}

	/**
	 * @return list<\AlohaSocial\Core\Jobs\Job>
	 */
	private function jobsOfActivityType(string $type): array
	{
		return array_values(array_filter(
			$this->queue->pending(),
			static fn ($job): bool => ($job->payload['activity']['type'] ?? null) === $type,
		));
	}
}
