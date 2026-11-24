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
use AlohaSocial\Core\Domain\Post\QuoteState;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbed;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacets;
use AlohaSocial\Core\Protocol\Atproto\AtprotoIdentityResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyBuilder;
use AlohaSocial\Core\Protocol\Atproto\AtprotoQuoteBuilder;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoBlobUploader;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
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

	public function testDeleteStillRunsAfterPublishingIsDisabled(): void
	{
		$post = $this->post();
		$this->posts->insert($post);
		$this->publications->insert(new AtprotoPublication(
			'alice',
			$post->id,
			'post1',
			self::URI,
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$service = new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'false']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			new XrpcAtprotoPublisher($this->http),
			$this->queue,
			$clock,
			new XrpcAtprotoPublisher($this->http),
		);
		$this->http->queueResponse(new HttpResponse(200, [], '{}'));

		$service->deleteRemote('alice', 'post-1');

		self::assertNull($this->publications->findForPost('alice', 'post-1'));
		self::assertCount(1, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.deleteRecord', $this->http->requests[0]['url']);
	}

	public function testDeleteIsQueuedAfterPublishingIsDisabled(): void
	{
		$post = $this->post();
		$this->publications->insert(new AtprotoPublication(
			'alice',
			$post->id,
			'post1',
			self::URI,
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$service = new AtprotoPublishService(
			new ArrayConfig([InstanceInfo::CONFIG_APP => ['atproto.publish' => 'false']]),
			$this->posts,
			$this->publications,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
			new XrpcAtprotoPublisher($this->http),
			$this->queue,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			new XrpcAtprotoPublisher($this->http),
		);

		$service->scheduleDelete($post);

		$jobs = $this->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('atproto.delete', $jobs[0]->type);
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

	public function testEditedPublishedPostCanUpsertItsExistingPdsRecord(): void
	{
		$post = $this->post(content: 'edited public text');
		$this->posts->insert($post);
		$publication = new AtprotoPublication('alice', $post->id, 'post1', self::URI, Time::parse('2026-11-11T09:00:00Z'), cid: 'bafy-old');
		$this->publications->insert($publication);
		$this->http->queueResponseFactory($this->published());

		$result = $this->serviceWith()->publish('alice', $post->id, update: true);

		$write = json_decode($this->http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('post1', $write['rkey']);
		self::assertSame('edited public text', $write['record']['text']);
		self::assertNotSame($publication, $result);
		self::assertSame('bafy-record', $result->cid);
		self::assertSame($result, $this->publications->findForPost('alice', $post->id));
	}

	public function testUpdateJobDoesNotCreateANewPublicationWhenTheOriginalRowIsGone(): void
	{
		$post = $this->post();
		$this->posts->insert($post);

		try {
			$this->serviceWith()->publish('alice', $post->id, update: true);
			self::fail('An update job must not create a publication from scratch.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::PUBLICATION_MISSING, $exception->reason);
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testAcceptedLocalQuotePublishesVerifiedRecordEmbed(): void
	{
		$target = $this->post(id: 'quoted-post');
		$this->posts->insert($target);
		$quotedUri = 'at://did:plc:alice/app.bsky.feed.post/quoted';
		$this->publications->insert(new AtprotoPublication(
			'alice',
			$target->id,
			'quoted',
			$quotedUri,
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$config = new ArrayConfig([InstanceInfo::CONFIG_APP => [
			'atproto.publish' => 'true',
			'instance.url' => 'https://social.example',
		]]);
		$quotePost = $this->post(
			id: 'quote-post',
			quotedObjectId: (new ActivityFactory($config))->noteId($target),
			quoteState: QuoteState::Accepted,
		);
		$this->posts->insert($quotePost);
		$records = new class ($quotedUri) implements AtprotoRecordResolver {
			public function __construct(private readonly string $uri)
			{
			}

			public function getRecord(string $pdsUrl, string $uri): ?AtprotoRecord
			{
				return $uri === $this->uri
					? new AtprotoRecord($uri, 'bafkquotedcid', ['$type' => 'app.bsky.feed.post'])
					: null;
			}
		};
		$this->http->queueResponse($this->blobResponse('bafkimagecid', 'image/jpeg', 5));
		$this->http->queueResponseFactory($this->published());

		$this->serviceWith(quoteRecords: $records)->publish('alice', $quotePost->id, [
			new AtprotoMedia('image/jpeg', 'bytes', 'a sample image'),
		]);

		$write = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		$embed = $write['record']['embed'];
		self::assertSame('app.bsky.embed.recordWithMedia', $embed['$type']);
		self::assertSame('app.bsky.embed.record', $embed['record']['$type']);
		self::assertSame(['uri' => $quotedUri, 'cid' => 'bafkquotedcid'], $embed['record']['record']);
		self::assertSame('app.bsky.embed.images', $embed['media']['$type']);
	}

	public function testAcceptedQuoteWithoutAnAtprotoTargetIsRefusedBeforeWriting(): void
	{
		$quotePost = $this->post(
			id: 'quote-post',
			quotedObjectId: 'https://remote.example/posts/1',
			quoteState: QuoteState::Accepted,
		);
		$this->posts->insert($quotePost);

		try {
			$this->serviceWith(quoteRecords: $this->recordResolver())->publish('alice', $quotePost->id);
			self::fail('An external quote without an AT URI mapping must be refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoPostRejectedException::QUOTE_UNSUPPORTED, $exception->reason);
		}

		self::assertCount(0, $this->http->requests);
	}

	public function testScheduleUpdateUsesExistingPublicationAndSameAccountSubject(): void
	{
		$post = $this->post(content: 'edited');
		$this->publications->insert(new AtprotoPublication('alice', $post->id, 'post1', self::URI, Time::parse('2026-11-11T09:00:00Z')));

		$this->service->scheduleUpdate($post);

		$jobs = $this->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('atproto.publish', $jobs[0]->type);
		self::assertSame(['user_id' => 'alice', 'post_id' => 'post-1', 'update' => true], $jobs[0]->payload);
	}

	public function testUnrepresentableEditSchedulesDeletionOfExistingPublicMirror(): void
	{
		$post = $this->post(content: str_repeat('x', 301));
		$this->publications->insert(new AtprotoPublication('alice', $post->id, 'post1', self::URI, Time::parse('2026-11-11T09:00:00Z')));

		$this->service->scheduleUpdate($post);

		$jobs = $this->queue->pending();
		self::assertCount(1, $jobs);
		self::assertSame('atproto.delete', $jobs[0]->type);
		self::assertSame(['user_id' => 'alice', 'post_id' => 'post-1'], $jobs[0]->payload);
	}

	public function testFacetsArePartOfThePublishedRecord(): void
	{
		$post = $this->post(content: 'hello #tag and https://example.com/x');
		$this->posts->insert($post);
		$this->http->queueResponseFactory($this->published());

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
		$this->http->queueResponseFactory($this->published());

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
		$this->http->queueResponseFactory($this->published());

		$this->serviceWith()->publish('alice', 'reply');

		$record = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR)['record'];
		self::assertSame([
			'root' => ['uri' => $parentUri, 'cid' => 'bafy-parent'],
			'parent' => ['uri' => $parentUri, 'cid' => 'bafy-parent'],
		], $record['reply']);
		self::assertArrayNotHasKey('embed', $record);
	}

	public function testMediaIsUploadedAndEmbedded(): void
	{
		$post = $this->post(content: 'look');
		$this->posts->insert($post);
		$this->http->queueResponse($this->blobResponse('bafy-img', 'image/png', 3));
		$this->http->queueResponseFactory($this->published());

		$this->serviceWith()->publish('alice', $post->id, [
			new AtprotoMedia('image/png', 'png', 'a cat', 800, 600),
		]);

		self::assertSame(
			'https://pds.example/xrpc/com.atproto.repo.uploadBlob',
			$this->http->requests[0]['url'],
		);
		self::assertSame('png', $this->http->requests[0]['body']);
		self::assertSame('image/png', $this->http->requests[0]['headers']['Content-Type']);

		$record = $this->publishedRecord();
		self::assertSame('app.bsky.embed.images', $record['embed']['$type']);
		self::assertSame(['$link' => 'bafy-img'], $record['embed']['images'][0]['image']['ref']);
		self::assertSame('a cat', $record['embed']['images'][0]['alt']);
		self::assertSame(['width' => 800, 'height' => 600], $record['embed']['images'][0]['aspectRatio']);
	}

	public function testAVideoIsEmbedded(): void
	{
		$post = $this->post(content: 'watch');
		$this->posts->insert($post);
		$this->http->queueResponse($this->blobResponse('bafy-video', 'video/mp4', 4));
		$this->http->queueResponseFactory($this->published());

		$this->serviceWith()->publish('alice', $post->id, [
			new AtprotoMedia('video/mp4', 'mp4', 'a clip'),
		]);

		$embed = $this->publishedRecord()['embed'];
		self::assertSame('app.bsky.embed.video', $embed['$type']);
		self::assertSame(['$link' => 'bafy-video'], $embed['video']['ref']);
		self::assertSame('a clip', $embed['alt']);
	}

	public function testAnUnsupportedMediaTypeEndsWithoutAnyRequest(): void
	{
		$post = $this->post(content: 'look');
		$this->posts->insert($post);

		try {
			$this->serviceWith()->publish('alice', $post->id, [
				new AtprotoMedia('image/gif', 'gif', 'alt'),
			]);
			self::fail('An unsupported media type should have been refused.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoBlobException::MIME_UNSUPPORTED, $exception->reason);
		}

		self::assertCount(0, $this->http->requests);
		self::assertCount(0, $this->queue->pending());
	}

	public function testAFailedUploadLeavesNoRecord(): void
	{
		$post = $this->post(content: 'look');
		$this->posts->insert($post);
		$this->http->queueResponse(new HttpResponse(500, [], '{"error":"InternalServerError"}'));

		try {
			$this->serviceWith()->publish('alice', $post->id, [
				new AtprotoMedia('image/png', 'png', 'alt'),
			]);
			self::fail('A failed upload should have refused the post.');
		} catch (AtprotoPostRejectedException $exception) {
			self::assertSame(AtprotoBlobException::UPLOAD_FAILED, $exception->reason);
		}

		self::assertCount(1, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.uploadBlob', $this->http->requests[0]['url']);
		self::assertNull($this->publications->findForPost('alice', $post->id));
	}

	public function testAPlainTextPostStillHasNoEmbed(): void
	{
		$post = $this->post(content: 'hello');
		$this->posts->insert($post);
		$this->http->queueResponseFactory($this->published());

		$this->serviceWith()->publish('alice', $post->id);

		self::assertCount(1, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.putRecord', $this->http->requests[0]['url']);
		self::assertArrayNotHasKey('embed', $this->publishedRecord());
	}

	public function testAUniqueExternalLinkGetsAFallbackCardWithoutRemoteLookup(): void
	{
		$post = $this->post(content: 'Read https://example.com/story.');
		$this->posts->insert($post);
		$this->http->queueResponseFactory($this->published());

		$this->serviceWith()->publish('alice', $post->id);

		self::assertSame([
			'$type' => 'app.bsky.embed.external',
			'external' => [
				'uri' => 'https://example.com/story',
				'title' => 'example.com',
				'description' => '',
			],
		], $this->publishedRecord()['embed']);
		self::assertCount(1, $this->http->requests, 'Publishing must not fetch link metadata.');
	}

	public function testSeveralLinksRemainTextWithoutChoosingAnArbitraryCard(): void
	{
		$multiple = $this->post(id: 'multiple-links', content: 'https://one.example https://two.example');
		$this->posts->insert($multiple);
		$this->http->queueResponseFactory($this->published());
		$this->serviceWith()->publish('alice', $multiple->id);
		self::assertArrayNotHasKey('embed', $this->publishedRecord());
	}

	public function testMediaEmbedTakesPriorityOverAnExternalLink(): void
	{
		$image = $this->post(id: 'image-link', content: 'https://example.com/image');
		$this->posts->insert($image);
		$this->http->queueResponse($this->blobResponse('bafy-img', 'image/png', 3));
		$this->http->queueResponseFactory($this->published());
		$this->serviceWith()->publish('alice', $image->id, [
			new AtprotoMedia('image/png', 'png', 'a cat'),
		]);
		self::assertSame('app.bsky.embed.images', $this->publishedRecord()['embed']['$type']);
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
		?AtprotoRecordResolver $quoteRecords = null,
	): AtprotoPublishService {
		$config = new ArrayConfig([InstanceInfo::CONFIG_APP => [
			'atproto.publish' => 'true',
			'instance.url' => 'https://social.example',
		]]);

		return new AtprotoPublishService(
			$config,
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
			new AtprotoEmbed(new XrpcAtprotoBlobUploader($this->http)),
			null,
			$quoteRecords === null ? null : new AtprotoQuoteBuilder($quoteRecords),
			new ActivityFactory($config),
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

	private function blobResponse(string $cid, string $mimeType, int $size): HttpResponse
	{
		return new HttpResponse(200, [], json_encode([
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => $cid],
				'mimeType' => $mimeType,
				'size' => $size,
			],
		], JSON_THROW_ON_ERROR));
	}

	private function published(): \Closure
	{
		return static function (string $method, string $url, array $headers, string $body): HttpResponse {
			$request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
			$uri = 'at://' . $request['repo'] . '/' . $request['collection'] . '/' . $request['rkey'];

			return new HttpResponse(200, [], json_encode(['uri' => $uri, 'cid' => 'bafy-record'], JSON_THROW_ON_ERROR));
		};
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
		?string $quotedObjectId = null,
		?QuoteState $quoteState = null,
	): Post {
		return new Post(
			$id,
			$authorId,
			$content,
			$visibility,
			Time::parse('2026-11-11T10:00:00Z'),
			attachmentIds: $attachmentIds,
			inReplyToId: $inReplyToId,
			quotedObjectId: $quotedObjectId,
			quoteState: $quoteState,
		);
	}
}
