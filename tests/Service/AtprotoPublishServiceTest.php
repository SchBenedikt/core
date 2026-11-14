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
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacets;
use AlohaSocial\Core\Protocol\Atproto\AtprotoIdentityResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyBuilder;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Core\Service\Atproto\AtprotoPostRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoPublishServiceTest extends TestCase
{
	private const URI = 'at://did:plc:alice/app.bsky.feed.post/post1';

	private InMemoryPostRepository $posts;

	private InMemoryJobQueue $queue;

	private AtprotoPublishService $service;

	private InMemoryAtprotoPublicationRepository $publications;

	private FakeHttpClient $http;

	protected function setUp(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->posts = new InMemoryPostRepository();
		$this->http = new FakeHttpClient();
		$this->queue = new InMemoryJobQueue($clock, new RandomIdGenerator());
		$this->publications = new InMemoryAtprotoPublicationRepository();
		$publisher = new XrpcAtprotoPublisher($this->http);

		$this->service = new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'true']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			$publisher,
			$this->queue,
			$clock,
			$publisher,
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

	public function testDeleteIsDisabledWithTheFeatureSwitch(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$service = new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'false']]),
			$this->posts,
			$this->publications,
			null,
			null,
			$this->queue,
			$clock,
		);

		$this->expectException(AtprotoPublishDisabledException::class);

		$service->deleteRemote('alice', 'post-1');
	}

	public function testDeleteWithoutAPublicationIsANoOp(): void
	{
		$this->service->deleteRemote('alice', 'post-1');

		self::assertCount(0, $this->http->requests);
		self::assertCount(0, $this->queue->pending());
	}

	public function testDeleteWithoutADeleterIsNotConfigured(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$service = new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'true']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([]),
			null,
			$this->queue,
			$clock,
		);

		$this->expectException(\AlohaSocial\Core\Service\Atproto\AtprotoNotConfiguredException::class);

		$service->deleteRemote('alice', 'post-1');
	}

	public function testScheduleSkipsWithoutAConnectedAccount(): void
	{
		$post = $this->post(authorId: 'bob');
		$this->posts->insert($post);

		$this->service->schedule($post);

		self::assertCount(0, $this->queue->pending());
	}

	public function testFacetsArePartOfThePublishedRecord(): void
	{
		$post = $this->post(content: 'hello #tag and https://example.com/x');
		$this->posts->insert($post);
		$this->http->queueResponse($this->published(self::URI));

		$this->serviceWith()->publish('alice', $post->id);

		$record = $this->publishedRecord();
		self::assertSame('app.bsky.richtext.facet#tag', $record['facets'][0]['features'][0]['$type']);
		self::assertSame('tag', $record['facets'][0]['features'][0]['tag']);
		self::assertSame(6, $record['facets'][0]['index']['byteStart']);
		self::assertSame('app.bsky.richtext.facet#link', $record['facets'][1]['features'][0]['$type']);
		self::assertSame('https://example.com/x', $record['facets'][1]['features'][0]['uri']);
		self::assertSame(strlen('hello #tag and '), $record['facets'][1]['index']['byteStart']);
	}

	public function testAMentionIsPublishedWithItsDid(): void
	{
		$post = $this->post(content: 'hi @alice.test');
		$this->posts->insert($post);
		$this->http->queueResponse($this->published(self::URI));

		$this->serviceWith(identities: $this->identities(['alice.test' => 'did:plc:alice']))
			->publish('alice', $post->id);

		self::assertSame(
			[['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:alice']],
			$this->publishedRecord()['facets'][0]['features'],
		);
	}

	public function testAnUnresolvableMentionIsRefusedWithoutAPdsWrite(): void
	{
		$post = $this->post(content: 'hi @unknown.test');
		$this->posts->insert($post);

		try {
			$this->serviceWith(identities: $this->identities([]))->publish('alice', $post->id);
			self::fail('An unresolvable mention should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::MENTION_UNRESOLVED, $exception->reason);
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testAReplyCarriesRootAndParentWithCids(): void
	{
		$parentUri = 'at://did:plc:alice/app.bsky.feed.post/parent';
		$this->posts->insert($this->post(id: 'parent'));
		$this->posts->insert($this->post(id: 'reply', inReplyToId: 'parent'));
		$this->publications->insert(new AtprotoPublication(
			'alice',
			'parent',
			'parent-rkey',
			$parentUri,
			Time::parse('2026-11-11T10:00:00Z'),
		));
		$this->http->queueResponse($this->record($parentUri, 'bafy-parent', ['text' => 'parent']));
		$this->http->queueResponse($this->published('at://did:plc:alice/app.bsky.feed.post/reply'));

		$this->serviceWith()->publish('alice', 'reply');

		$record = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR)['record'];
		self::assertSame([
			'root' => ['uri' => $parentUri, 'cid' => 'bafy-parent'],
			'parent' => ['uri' => $parentUri, 'cid' => 'bafy-parent'],
		], $record['reply']);
	}

	public function testAReplyToAnUnpublishedParentIsSkippedAndRefused(): void
	{
		$post = $this->post(id: 'reply', inReplyToId: 'parent');
		$this->posts->insert($post);

		$this->service->schedule($post);
		self::assertCount(0, $this->queue->pending());

		try {
			$this->serviceWith()->publish('alice', 'reply');
			self::fail('A reply to a local-only parent should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::REPLY_PARENT_NOT_PUBLISHED, $exception->reason);
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testAnUnresolvableReplyParentIsRefused(): void
	{
		$post = $this->post(id: 'reply', inReplyToId: 'parent');
		$this->posts->insert($post);
		$this->publications->insert(new AtprotoPublication(
			'alice',
			'parent',
			'parent-rkey',
			'at://did:plc:alice/app.bsky.feed.post/parent',
			Time::parse('2026-11-11T10:00:00Z'),
		));
		$this->http->queueResponse(new HttpResponse(400, [], '{"error":"RecordNotFound"}'));

		try {
			$this->serviceWith()->publish('alice', 'reply');
			self::fail('An unresolvable parent should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::REPLY_PARENT_UNRESOLVABLE, $exception->reason);
		}

		self::assertCount(1, $this->http->requests);
	}

	private function serviceWith(
		?AtprotoIdentityResolver $identities = null,
	): AtprotoPublishService {
		return new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'true']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			new XrpcAtprotoPublisher($this->http),
			$this->queue,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			null,
			new AtprotoFacets($identities),
			new AtprotoReplyBuilder($this->recordResolver()),
		);
	}

	private function recordResolver(): AtprotoRecordResolver
	{
		return new XrpcAtprotoRecordResolver($this->http);
	}

	/**
	 * @param array<string, string> $dids
	 */
	private function identities(array $dids): AtprotoIdentityResolver
	{
		return new class ($dids) implements AtprotoIdentityResolver {
			/**
			 * @param array<string, string> $dids
			 */
			public function __construct(
				private readonly array $dids,
			) {
			}

			public function resolveHandle(string $pdsUrl, string $handle): ?string
			{
				return $this->dids[$handle] ?? null;
			}
		};
	}

	private function published(string $uri): HttpResponse
	{
		return new HttpResponse(200, [], json_encode(['uri' => $uri], JSON_THROW_ON_ERROR));
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private function record(string $uri, string $cid, array $value): HttpResponse
	{
		return new HttpResponse(200, [], json_encode([
			'uri' => $uri,
			'cid' => $cid,
			'value' => $value,
		], JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function publishedRecord(): array
	{
		$requests = array_values(array_filter(
			$this->http->requests,
			static fn (array $request): bool => str_contains($request['url'], '/xrpc/com.atproto.repo.putRecord'),
		));
		self::assertCount(1, $requests, 'Expected exactly one putRecord request.');

		return json_decode($requests[0]['body'], true, 64, JSON_THROW_ON_ERROR)['record'];
	}

	private function post(
		string $authorId = 'alice',
		string $content = 'hello',
		Visibility $visibility = Visibility::Public,
		array $attachmentIds = [],
		string $id = 'post-1',
		?string $inReplyToId = null,
	): Post {
		return new Post(
			$id,
			$authorId,
			$content,
			$visibility,
			Time::parse('2026-11-11T10:00:00Z'),
			attachmentIds: $attachmentIds,
			inReplyToId: $inReplyToId,
		);
	}
}
