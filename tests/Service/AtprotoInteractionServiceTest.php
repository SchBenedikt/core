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
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionService;
use AlohaSocial\Core\Service\Atproto\AtprotoNotConfiguredException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoInteractionServiceTest extends TestCase
{
	private const POST_URI = 'at://did:plc:alice/app.bsky.feed.post/post1rkey';

	private InMemoryPostRepository $posts;

	private InMemoryAtprotoPublicationRepository $publications;

	private InMemoryAtprotoInteractionRepository $interactionRecords;

	private InMemoryBlockRepository $blocks;

	private FakeHttpClient $http;

	private AtprotoInteractionService $service;

	protected function setUp(): void
	{
		$this->posts = new InMemoryPostRepository();
		$this->publications = new InMemoryAtprotoPublicationRepository();
		$this->interactionRecords = new InMemoryAtprotoInteractionRepository();
		$this->blocks = new InMemoryBlockRepository();
		$this->http = new FakeHttpClient();

		$this->service = $this->service(enabled: true);
	}

	public function testLikePublishesALikeRecordWithTheSubjectCid(): void
	{
		$this->publishedPost();
		$this->http->queueResponse($this->subjectRecord());
		$this->http->queueResponseFactory($this->publishedRecord());

		$uri = $this->service->like('alice', 'post-1');

		self::assertCount(2, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.getRecord', $this->http->requests[0]['url']);

		$body = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('at://' . $body['repo'] . '/' . $body['collection'] . '/' . $body['rkey'], $uri);
		self::assertSame('did:plc:alice', $body['repo']);
		self::assertSame('app.bsky.feed.like', $body['collection']);
		self::assertSame('app.bsky.feed.like', $body['record']['$type']);
		self::assertSame(
			['uri' => self::POST_URI, 'cid' => 'bafy-subject'],
			$body['record']['subject'],
		);
		self::assertSame('2026-11-11T10:00:00Z', $body['record']['createdAt']);
		self::assertTrue($body['validate']);
		$stored = $this->interactionRecords->find('alice', 'did:plc:alice', 'post-1', 'like');
		self::assertNotNull($stored);
		self::assertSame($uri, $stored->uri);
		self::assertSame($body['rkey'], $stored->rkey);

		self::assertSame($uri, $this->service->like('alice', 'post-1'));
		self::assertCount(2, $this->http->requests, 'A retry must return the stored record without a second PDS write.');
	}

	public function testSubjectRecordIsResolvedOnItsPublishersPds(): void
	{
		$post = new Post(
			'post-from-bob',
			'bob',
			'bobs public post',
			Visibility::Public,
			Time::parse('2026-11-11T10:00:00Z'),
		);
		$this->posts->insert($post);
		$uri = 'at://did:plc:bob/app.bsky.feed.post/bob-post';
		$this->publications->insert(new AtprotoPublication(
			'bob',
			$post->id,
			'bob-post',
			$uri,
			Time::parse('2026-11-11T10:00:00Z'),
			'https://bob-pds.example',
		));
		$publisher = new XrpcAtprotoPublisher($this->http);
		$service = new AtprotoInteractionService(
			new ArrayConfig(['social' => ['atproto.publish' => 'true']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://alice-pds.example', 'token'),
				'bob' => new AtprotoAccount('did:plc:bob', 'bob.test', 'https://bob-pds.example', 'bob-token'),
			]),
			$publisher,
			new XrpcAtprotoRecordResolver($this->http),
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			$publisher,
			$this->blocks,
			$this->activities(),
			$this->interactionRecords,
		);
		$this->http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => $uri,
			'cid' => 'bafy-bob-subject',
			'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'bobs public post'],
		], JSON_THROW_ON_ERROR)));
		$this->http->queueResponseFactory($this->publishedRecord());

		$service->like('alice', $post->id);

		self::assertStringStartsWith('https://bob-pds.example/xrpc/com.atproto.repo.getRecord', $this->http->requests[0]['url']);
		self::assertStringStartsWith('https://alice-pds.example/xrpc/com.atproto.repo.putRecord', $this->http->requests[1]['url']);
		$write = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame(['uri' => $uri, 'cid' => 'bafy-bob-subject'], $write['record']['subject']);
	}

	public function testUnlikeRemovesExactlyTheRecordTheLikeCreated(): void
	{
		$this->publishedPost();
		$this->http->queueResponse($this->subjectRecord());
		$this->http->queueResponseFactory($this->publishedRecord());
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
		self::assertNull($this->interactionRecords->find('alice', 'did:plc:alice', 'post-1', 'like'));
	}

	public function testRepostAndUndoUseTheRepostCollection(): void
	{
		$this->publishedPost();
		$this->http->queueResponse($this->subjectRecord());
		$this->http->queueResponseFactory($this->publishedRecord());
		$this->service->repost('alice', 'post-1');

		$body = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.repost', $body['collection']);
		self::assertSame('app.bsky.feed.repost', $body['record']['$type']);

		$this->http->queueResponse(new HttpResponse(200, [], '{}'));
		$this->service->undoRepost('alice', 'post-1');

		$delete = json_decode($this->http->requests[2]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.repost', $delete['collection']);
		self::assertSame($body['rkey'], $delete['rkey']);
		self::assertNull($this->interactionRecords->find('alice', 'did:plc:alice', 'post-1', 'repost'));
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

	public function testBlocksInEitherDirectionPreventLikeAndRepostPublication(): void
	{
		$target = new Post(
			'target-post',
			'bob',
			'public subject',
			Visibility::Public,
			Time::parse('2026-11-11T10:00:00Z'),
		);
		$this->posts->insert($target);
		$this->publications->insert(new AtprotoPublication(
			'bob',
			'target-post',
			'target1rkey',
			'at://did:plc:bob/app.bsky.feed.post/target1rkey',
			Time::parse('2026-11-11T10:00:00Z'),
		));

		foreach ([
			['alice', 'bob'], // the interacting account blocked the post author
			['bob', 'alice'], // the post author blocked the interacting account
		] as [$blocker, $blocked]) {
			$this->blocks->insert(new Block(
				id: 'block-' . $blocker . '-' . $blocked,
				localUserId: $blocker,
				actorId: 'https://social.example/@' . $blocked,
				blockedAt: Time::parse('2026-11-11T09:00:00Z'),
			));

			foreach (['like', 'repost'] as $interaction) {
				try {
					$this->service->{$interaction}('alice', 'target-post');
					self::fail('A block in either direction must prevent publishing the interaction.');
				} catch (AtprotoInteractionRejectedException $exception) {
					self::assertSame(AtprotoInteractionRejectedException::BLOCKED_ACTOR, $exception->reason);
				}
			}
			self::assertTrue($this->blocks->remove('block-' . $blocker . '-' . $blocked, $blocker));
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testMissingBlockPolicyRefusesToPublishAnInteraction(): void
	{
		$this->publishedPost();
		$publisher = new XrpcAtprotoPublisher($this->http);
		$service = new AtprotoInteractionService(
			new ArrayConfig(['social' => ['atproto.publish' => 'true']]),
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

		try {
			$service->like('alice', 'post-1');
			self::fail('The interaction must refuse to publish without a block policy.');
		} catch (AtprotoNotConfiguredException) {
			self::assertCount(0, $this->http->requests);
		}
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
			$this->blocks,
			$this->activities(),
			$this->interactionRecords,
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
			$this->blocks,
			$this->activities(),
			$this->interactionRecords,
		);
	}

	private function activities(): ActivityFactory
	{
		return new ActivityFactory(new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example'],
		]));
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

	private function publishedRecord(): \Closure
	{
		return static function (string $method, string $url, array $headers, string $body): HttpResponse {
			$request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
			$uri = 'at://' . $request['repo'] . '/' . $request['collection'] . '/' . $request['rkey'];

			return new HttpResponse(200, [], json_encode([
				'uri' => $uri,
				'cid' => 'bafy-interaction',
			], JSON_THROW_ON_ERROR));
		};
	}
}
