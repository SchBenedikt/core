<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublication;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Service\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionService;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoInteractionServiceTest extends TestCase
{
	private const POST_URI = 'at://did:plc:alice/app.bsky.feed.post/post1rkey';

	private InMemoryPostRepository $posts;

	private InMemoryAtprotoPublicationRepository $publications;

	private FakeHttpClient $http;

	private AtprotoInteractionService $service;

	protected function setUp(): void
	{
		$this->posts = new InMemoryPostRepository();
		$this->publications = new InMemoryAtprotoPublicationRepository();
		$this->http = new FakeHttpClient();

		$this->service = $this->service(enabled: true);
	}

	public function testLikePublishesALikeRecordWithTheSubjectCid(): void
	{
		$this->publishedPost();
		$this->http->queueResponse($this->subjectRecord());
		$this->http->queueResponse($this->publishedRecord('app.bsky.feed.like'));

		$uri = $this->service->like('alice', 'post-1');

		self::assertSame('at://did:plc:alice/app.bsky.feed.like/rkey1', $uri);
		self::assertCount(2, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.getRecord', $this->http->requests[0]['url']);

		$body = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('did:plc:alice', $body['repo']);
		self::assertSame('app.bsky.feed.like', $body['collection']);
		self::assertSame('app.bsky.feed.like', $body['record']['$type']);
		self::assertSame(
			['uri' => self::POST_URI, 'cid' => 'bafy-subject'],
			$body['record']['subject'],
		);
		self::assertSame('2026-11-11T10:00:00Z', $body['record']['createdAt']);
		self::assertTrue($body['validate']);
	}

	public function testUnlikeRemovesExactlyTheRecordTheLikeCreated(): void
	{
		$this->publishedPost();
		$this->http->queueResponse($this->subjectRecord());
		$this->http->queueResponse($this->publishedRecord('app.bsky.feed.like'));
		$this->service->like('alice', 'post-1');
		$like = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);

		$this->http->queueResponse(new HttpResponse(200, [], '{}'));
		$this->service->unlike('alice', 'post-1');

		self::assertCount(3, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.deleteRecord', $this->http->requests[2]['url']);
		$delete = json_decode($this->http->requests[2]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('did:plc:alice', $delete['repo']);
		self::assertSame('app.bsky.feed.like', $delete['collection']);
		self::assertSame($like['rkey'], $delete['rkey']);
	}

	public function testRepostAndUndoUseTheRepostCollection(): void
	{
		$this->publishedPost();
		$this->http->queueResponse($this->subjectRecord());
		$this->http->queueResponse($this->publishedRecord('app.bsky.feed.repost'));
		$this->service->repost('alice', 'post-1');

		$body = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.repost', $body['collection']);
		self::assertSame('app.bsky.feed.repost', $body['record']['$type']);

		$this->http->queueResponse(new HttpResponse(200, [], '{}'));
		$this->service->undoRepost('alice', 'post-1');

		$delete = json_decode($this->http->requests[2]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.repost', $delete['collection']);
		self::assertSame($body['rkey'], $delete['rkey']);
	}

	public function testTheFeatureSwitchRefusesInteractions(): void
	{
		$this->publishedPost();
		$service = $this->service(enabled: false);

		$this->expectException(AtprotoPublishDisabledException::class);

		$service->like('alice', 'post-1');
	}

	public function testAPostWithoutAPublicationIsRefused(): void
	{
		$this->posts->insert($this->post());

		try {
			$this->service->like('alice', 'post-1');
			self::fail('A post without a publication should have been refused.');
		} catch (AtprotoInteractionRejectedException $exception) {
			self::assertSame(
				AtprotoInteractionRejectedException::SUBJECT_NOT_PUBLISHED,
				$exception->reason,
			);
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testPrivateSubjectsAreNeverMirroredAsPublicInteractions(): void
	{
		foreach ([Visibility::Unlisted, Visibility::Followers, Visibility::Direct] as $visibility) {
			$postId = 'private-' . $visibility->value;
			$rkey = 'post1' . $visibility->value;
			$post = new Post(
				$postId,
				'alice',
				'private content',
				$visibility,
				Time::parse('2026-11-11T10:00:00Z'),
			);
			$this->posts->insert($post);
			$this->publications->insert(new AtprotoPublication(
				'alice',
				$postId,
				$rkey,
				'at://did:plc:alice/app.bsky.feed.post/' . $rkey,
				Time::parse('2026-11-11T10:00:00Z'),
			));

			try {
				$this->service->like('alice', $postId);
				self::fail('A non-public post must not produce a public AT Protocol interaction.');
			} catch (AtprotoInteractionRejectedException $exception) {
				self::assertSame(
					AtprotoInteractionRejectedException::SUBJECT_NOT_PUBLIC,
					$exception->reason,
				);
			}
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testAnUnresolvableSubjectIsRefused(): void
	{
		$this->publishedPost();
		$this->http->queueResponse(new HttpResponse(400, [], '{"error":"RecordNotFound"}'));

		$this->expectException(AtprotoInteractionRejectedException::class);

		try {
			$this->service->like('alice', 'post-1');
		} catch (AtprotoInteractionRejectedException $exception) {
			self::assertSame(
				AtprotoInteractionRejectedException::SUBJECT_UNRESOLVABLE,
				$exception->reason,
			);

			throw $exception;
		}
	}

	public function testAMissingAccountIsRefused(): void
	{
		$this->publishedPost();
		$service = new AtprotoInteractionService(
			new ArrayConfig(['social' => ['atproto.publish' => 'true']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([]),
			new XrpcAtprotoPublisher($this->http),
			new XrpcAtprotoRecordResolver($this->http),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			new XrpcAtprotoPublisher($this->http),
		);

		$this->expectException(AtprotoAccountNotConnectedException::class);

		$service->like('alice', 'post-1');
	}

	public function testAMissingPostIsNotFound(): void
	{
		$this->expectException(PostNotFoundException::class);

		$this->service->like('alice', 'missing');
	}

	private function service(bool $enabled): AtprotoInteractionService
	{
		$publisher = new XrpcAtprotoPublisher($this->http);

		return new AtprotoInteractionService(
			new ArrayConfig(['social' => ['atproto.publish' => $enabled ? 'true' : 'false']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			$publisher,
			new XrpcAtprotoRecordResolver($this->http),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			$publisher,
		);
	}

	private function publishedPost(): void
	{
		$this->posts->insert($this->post());
		$this->publications->insert(new AtprotoPublication(
			'alice',
			'post-1',
			'post1rkey',
			self::POST_URI,
			Time::parse('2026-11-11T10:00:00Z'),
		));
	}

	private function post(): Post
	{
		return new Post(
			'post-1',
			'alice',
			'hello',
			Visibility::Public,
			Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	private function subjectRecord(): HttpResponse
	{
		return new HttpResponse(200, [], json_encode([
			'uri' => self::POST_URI,
			'cid' => 'bafy-subject',
			'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'hello'],
		], JSON_THROW_ON_ERROR));
	}

	private function publishedRecord(string $collection): HttpResponse
	{
		return new HttpResponse(200, [], json_encode([
			'uri' => 'at://did:plc:alice/' . $collection . '/rkey1',
			'cid' => 'bafy-interaction',
		], JSON_THROW_ON_ERROR));
	}
}
