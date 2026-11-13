<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
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
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
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

	private InMemoryJobQueue $queue;

	private SequenceIdGenerator $ids;

	private ArrayConfig $config;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->repository = new InMemoryPostRepository();
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

	private function service(?InboxResolver $inboxes = null, ?ArrayConfig $config = null): PostService
	{
		return new PostService(
			$this->repository,
			new InMemoryAttachmentRepository(),
			$this->queue,
			$inboxes ?? new EmptyInboxResolver(),
			new ActivityFactory($config ?? $this->config),
			$this->clock,
			$this->ids,
		);
	}
}
