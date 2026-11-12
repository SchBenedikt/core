<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Service\Atproto\AtprotoPostRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoPublishServiceTest extends TestCase
{
	private InMemoryPostRepository $posts;

	private InMemoryJobQueue $queue;

	private AtprotoPublishService $service;

	private FakeHttpClient $http;

	protected function setUp(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->posts = new InMemoryPostRepository();
		$this->http = new FakeHttpClient();
		$this->queue = new InMemoryJobQueue($clock, new RandomIdGenerator());

		$this->service = new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'true']]),
			$this->posts,
			new InMemoryAtprotoPublicationRepository(),
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			new XrpcAtprotoPublisher($this->http),
			$this->queue,
			$clock,
		);
	}

	public function testPostsWithAttachmentsAreSkippedAndRefused(): void
	{
		$post = $this->post(attachmentIds: ['attachment-1']);
		$this->posts->insert($post);

		$this->service->schedule($post);
		self::assertCount(0, $this->queue->pending());

		try {
			$this->service->publish('alice', $post->id);
			self::fail('An attachment post should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::ATTACHMENTS_UNSUPPORTED, $exception->reason);
		}
	}

	public function testNonPublicPostsAreSkippedAndRefused(): void
	{
		$post = $this->post(visibility: Visibility::Followers);
		$this->posts->insert($post);

		$this->service->schedule($post);
		self::assertCount(0, $this->queue->pending());

		try {
			$this->service->publish('alice', $post->id);
			self::fail('A non-public post should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::NOT_PUBLIC, $exception->reason);
		}
	}

	public function testLongTextIsSkippedAndRefused(): void
	{
		$post = $this->post(content: str_repeat('a', 301));
		$this->posts->insert($post);

		$this->service->schedule($post);
		self::assertCount(0, $this->queue->pending());

		try {
			$this->service->publish('alice', $post->id);
			self::fail('A long post should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::TEXT_TOO_LONG, $exception->reason);
		}
	}

	public function testAPostFromAnotherAccountIsNotFound(): void
	{
		$post = $this->post();
		$this->posts->insert($post);

		$this->expectException(\AlohaSocial\Core\Domain\Post\PostNotFoundException::class);

		$this->service->publish('bob', $post->id);
	}

	public function testScheduleSkipsWithoutAConnectedAccount(): void
	{
		$post = $this->post(authorId: 'bob');
		$this->posts->insert($post);

		$this->service->schedule($post);

		self::assertCount(0, $this->queue->pending());
	}

	private function post(
		string $authorId = 'alice',
		string $content = 'hello',
		Visibility $visibility = Visibility::Public,
		array $attachmentIds = [],
	): Post {
		return new Post(
			'post-1',
			$authorId,
			$content,
			$visibility,
			Time::parse('2026-11-11T10:00:00Z'),
			attachmentIds: $attachmentIds,
		);
	}
}
