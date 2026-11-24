<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Media\MediaKind;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The complete AT Protocol publication path:
 * local post -> queued job or explicit route -> XRPC putRecord -> idempotency
 * row -> JSON. The HTTP adapter is a fake so the test can inspect the exact
 * request without a PDS account.
 */
final class AtprotoPublishTest extends TestCase
{
	private const ALICE = 'alice';

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private AtprotoPublicationRepository $publications;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->http = new FakeHttpClient();
		$this->publications = new InMemoryAtprotoPublicationRepository();
	}

	public function testCreatingAPostQueuesAndPublishesIt(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());

		$post = $this->createPost($app, 'hello AT Protocol');

		self::assertCount(0, $this->http->requests, 'Creating a post must not perform a network write inline.');

		self::assertSame(1, $app->worker->runOnce());

		self::assertCount(1, $this->http->requests);
		$request = json_decode($this->http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.feed.post', $request['collection']);
		self::assertSame('hello AT Protocol', $request['record']['text']);
		self::assertSame('2026-11-11T10:00:00Z', $request['record']['createdAt']);

		$publication = $this->publications->findForPost(self::ALICE, $post);
		self::assertNotNull($publication);
		self::assertSame($this->requestedUri(), $publication->uri);
	}

	public function testCreatingAnImagePostUploadsItsStoredAttachmentBeforePublishing(): void
	{
		$attachmentId = 'attachment-image-1';
		$attachment = new Attachment(
			id: $attachmentId,
			ownerId: self::ALICE,
			storageKey: 'media/' . $attachmentId . '/original.png',
			thumbnailKey: 'media/' . $attachmentId . '/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 3,
			altText: 'A small test image',
			createdAt: $this->clock->now(),
			kind: MediaKind::Image,
		);
		$attachments = new InMemoryAttachmentRepository();
		$attachments->insert($attachment);
		$blobs = new InMemoryBlobStore();
		$blobs->put($attachment->storageKey, 'png');
		$app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.url' => 'https://social.example', 'atproto.publish' => 'true'],
			]),
			httpClient: $this->http,
			attachmentRepository: $attachments,
			blobStore: $blobs,
			atprotoAccounts: new InMemoryAtprotoAccountResolver([self::ALICE => $this->account()]),
			atprotoPublications: $this->publications,
		);
		$post = $app->posts->create(new CreatePost(
			self::ALICE,
			'Image post',
			attachmentIds: [$attachmentId],
		));

		self::assertCount(1, $app->queue->pending(), 'The valid image post should be queued for mirroring.');
		$this->http->queueResponse(new HttpResponse(200, [], json_encode([
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafy-image'],
				'mimeType' => 'image/png',
				'size' => 3,
			],
		], JSON_THROW_ON_ERROR)));
		$this->http->queueResponseFactory($this->success());

		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(2, $this->http->requests);
		self::assertStringContainsString('com.atproto.repo.uploadBlob', $this->http->requests[0]['url']);
		self::assertSame('png', $this->http->requests[0]['body']);

		$recordRequest = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.embed.images', $recordRequest['record']['embed']['$type']);
		self::assertSame('A small test image', $recordRequest['record']['embed']['images'][0]['alt']);
		self::assertSame(['$link' => 'bafy-image'], $recordRequest['record']['embed']['images'][0]['image']['ref']);
		self::assertNotNull($this->publications->findForPost(self::ALICE, $post->id));

		$gifId = 'attachment-image-gif';
		$attachments->insert(new Attachment(
			id: $gifId,
			ownerId: self::ALICE,
			storageKey: 'media/' . $gifId . '/original.gif',
			thumbnailKey: 'media/' . $gifId . '/thumbnail.gif',
			mimeType: 'image/gif',
			width: 20,
			height: 20,
			byteSize: 3,
			altText: 'Animated image',
			createdAt: $this->clock->now(),
			kind: MediaKind::Image,
		));
		$blobs->put('media/' . $gifId . '/original.gif', 'gif');
		$app->posts->create(new CreatePost(self::ALICE, 'GIF post', attachmentIds: [$gifId]));
		self::assertCount(0, $app->queue->pending(), 'Unsupported media should not be queued for a permanent refusal.');
	}

	public function testATemporaryBlobUploadFailureRetriesTheQueuedPublication(): void
	{
		$attachmentId = 'attachment-retry';
		$attachment = new Attachment(
			id: $attachmentId,
			ownerId: self::ALICE,
			storageKey: 'media/' . $attachmentId . '/original.png',
			thumbnailKey: 'media/' . $attachmentId . '/thumbnail.png',
			mimeType: 'image/png',
			width: 20,
			height: 20,
			byteSize: 3,
			altText: 'Retry image',
			createdAt: $this->clock->now(),
			kind: MediaKind::Image,
		);
		$attachments = new InMemoryAttachmentRepository();
		$attachments->insert($attachment);
		$blobs = new InMemoryBlobStore();
		$blobs->put($attachment->storageKey, 'png');
		$app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => ['instance.url' => 'https://social.example', 'atproto.publish' => 'true'],
			]),
			httpClient: $this->http,
			attachmentRepository: $attachments,
			blobStore: $blobs,
			atprotoAccounts: new InMemoryAtprotoAccountResolver([self::ALICE => $this->account()]),
			atprotoPublications: $this->publications,
		);
		$post = $app->posts->create(new CreatePost(
			self::ALICE,
			'Image post that should retry',
			attachmentIds: [$attachmentId],
		));

		$this->http->queueResponse(new HttpResponse(503, [], '{"error":"ServiceUnavailable"}'));
		$this->http->queueResponse(new HttpResponse(200, [], json_encode([
			'blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafy-retry-image'],
				'mimeType' => 'image/png',
				'size' => 3,
			],
		], JSON_THROW_ON_ERROR)));
		$this->http->queueResponseFactory($this->success());

		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(1, $app->queue->pendingCount(), 'A temporary upload failure must keep the job for retry.');
		self::assertNull($this->publications->findForPost(self::ALICE, $post->id));

		$this->clock->advance(30);
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(3, $this->http->requests);
		self::assertNotNull($this->publications->findForPost(self::ALICE, $post->id));
	}

	public function testARetryAfterAPdsFailureUsesTheSameRecordKey(): void
	{
		$app = $this->app();
		$this->http->queueResponse(new HttpResponse(500, [], '{"error":"InternalError"}'));
		$this->http->queueResponseFactory($this->success());
		$post = $this->createPost($app, 'retry me');

		self::assertSame(1, $app->worker->runOnce());
		self::assertNull($this->publications->findForPost(self::ALICE, $post));

		$this->clock->advance(30);
		self::assertSame(1, $app->worker->runOnce());

		self::assertCount(2, $this->http->requests);
		$first = json_decode($this->http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		$second = json_decode($this->http->requests[1]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame($first['rkey'], $second['rkey']);
		self::assertNotNull($this->publications->findForPost(self::ALICE, $post));
	}

	public function testDeletingAPostRemovesTheRemoteRecordAndPublication(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());
		$post = $this->createPost($app, 'delete me');
		self::assertSame(1, $app->worker->runOnce());

		$publication = $this->publications->findForPost(self::ALICE, $post);
		self::assertNotNull($publication);

		$this->http->queueResponse(new HttpResponse(200, [], '{}'));
		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/posts/' . rawurlencode($post),
			userId: self::ALICE,
		));

		self::assertSame(204, $deleted->status);
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(2, $this->http->requests);

		$request = $this->http->requests[1];
		self::assertSame('POST', $request['method']);
		self::assertSame('https://pds.example/xrpc/com.atproto.repo.deleteRecord', $request['url']);
		$body = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('did:plc:alice', $body['repo']);
		self::assertSame('app.bsky.feed.post', $body['collection']);
		self::assertSame($publication->rkey, $body['rkey']);
		self::assertArrayNotHasKey('record', $body);

		self::assertNull($this->publications->findForPost(self::ALICE, $post));
		self::assertSame(0, $app->worker->runOnce(), 'A second delete must not create a second job.');
	}

	public function testAFailedRemoteDeleteKeepsThePublicationAndRetries(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());
		$post = $this->createPost($app, 'retry delete');
		self::assertSame(1, $app->worker->runOnce());

		$this->http->queueResponse(new HttpResponse(500, [], '{"error":"InternalError"}'));
		$app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/posts/' . rawurlencode($post),
			userId: self::ALICE,
		));

		self::assertSame(1, $app->worker->runOnce());
		self::assertNotNull($this->publications->findForPost(self::ALICE, $post));

		$this->clock->advance(30);
		$this->http->queueResponse(new HttpResponse(200, [], '{}'));
		self::assertSame(1, $app->worker->runOnce());
		self::assertNull($this->publications->findForPost(self::ALICE, $post));
	}

	public function testDeletingAnUnpublishedPostDoesNotQueueARemoteDelete(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, 'never published');

		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/posts/' . rawurlencode($post),
			userId: self::ALICE,
		));

		self::assertSame(204, $deleted->status);
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(0, $this->http->requests);
		self::assertNull($this->publications->findForPost(self::ALICE, $post));
	}

	public function testTheExplicitRoutePublishesAndReturnsTheSameOnRepeat(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());
		$post = $this->createPost($app, 'hello route');

		$first = $this->publish($app, $post, 'did:plc:alice');
		$second = $this->publish($app, $post, 'did:plc:alice');

		self::assertSame(200, $first->status);
		self::assertSame(200, $second->status);
		self::assertSame($first->decodedBody()['publication'], $second->decodedBody()['publication']);
		self::assertSame('did:plc:alice', $first->decodedBody()['publication']['account_did']);
		self::assertCount(1, $this->http->requests, 'A repeat must return the stored publication.');
		self::assertSame(1, $this->publications->findForPost(self::ALICE, $post) === null ? 0 : 1);
	}

	public function testAnUnsupportedPostIsRefusedWithoutAWrite(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, str_repeat('a', 301));

		$response = $this->publish($app, $post);

		self::assertSame(422, $response->status);
		self::assertSame('atproto_post_unsupported', $response->decodedBody()['error']['code']);
		self::assertSame('text_too_long', $response->decodedBody()['error']['fields']['reason']);
		self::assertCount(0, $this->http->requests);
		self::assertNull($this->publications->findForPost(self::ALICE, $post));
	}

	public function testANonPublicPostIsNotScheduled(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'only followers', 'visibility' => 'followers'], JSON_THROW_ON_ERROR),
			userId: self::ALICE,
		));

		self::assertSame(201, $response->status);
		self::assertSame(0, $app->worker->runOnce());
		self::assertCount(0, $this->http->requests);
	}

	public function testTheRouteRequiresAuthentication(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, 'hello');

		$response = $app->handle(new Request('POST', '/api/v1/posts/' . $post . '/atproto'));

		self::assertSame(401, $response->status);
		self::assertSame('authentication_required', $response->decodedBody()['error']['code']);
	}

	public function testThePublicationStateIsReadableBeforeAndAfterPublishing(): void
	{
		$app = $this->app();
		$post = $this->createPost($app, 'status me');

		$before = $this->publicationStatus($app, $post, self::ALICE);

		self::assertSame(200, $before->status);
		self::assertNull($before->decodedBody()['publication']);

		$this->http->queueResponseFactory($this->success());
		self::assertSame(200, $this->publish($app, $post)->status);

		$after = $this->publicationStatus($app, $post, self::ALICE);

		self::assertSame(200, $after->status);
		self::assertSame($this->requestedUri(), $after->decodedBody()['publication']['uri']);
		self::assertSame($post, $after->decodedBody()['publication']['post_id']);
		self::assertSame('2026-11-11T10:00:00Z', $after->decodedBody()['publication']['published_at']);
	}

	public function testThePublicationStateIsOnlyVisibleToTheAuthor(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());
		$post = $this->createPost($app, 'author only');
		$this->publish($app, $post);

		$foreign = $this->publicationStatus($app, $post, 'bob');
		self::assertSame(404, $foreign->status);
		self::assertSame('not_found', $foreign->decodedBody()['error']['code']);

		$anonymous = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/posts/' . rawurlencode($post) . '/atproto',
		));
		self::assertSame(401, $anonymous->status);
		self::assertSame('authentication_required', $anonymous->decodedBody()['error']['code']);
	}

	public function testThePublicationStateIs404ForAnUnknownPost(): void
	{
		$response = $this->publicationStatus($this->app(), 'missing', self::ALICE);

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
	}

	public function testUnknownPostsAre404(): void
	{
		$response = $this->publish($this->app(), 'missing');

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
	}

	public function testAnAccountMustBeConnected(): void
	{
		$app = $this->app(accounts: new InMemoryAtprotoAccountResolver([
			'bob' => $this->account(),
		]));
		$post = $this->createPost($app, 'hello');

		$response = $this->publish($app, $post);

		self::assertSame(409, $response->status);
		self::assertSame('atproto_account_not_connected', $response->decodedBody()['error']['code']);
	}

	public function testTheFeatureIsDisabledByDefault(): void
	{
		$app = $this->app(enabled: false);
		$post = $this->createPost($app, 'hello');

		$response = $this->publish($app, $post);

		self::assertSame(503, $response->status);
		self::assertSame('atproto_publish_disabled', $response->decodedBody()['error']['code']);
		self::assertCount(0, $app->queue->pending());
	}

	public function testAPdsFailureLeavesNoPublicationRow(): void
	{
		$app = $this->app();
		$this->http->queueResponse(new HttpResponse(500, [], '{"error":"InternalError"}'));
		$post = $this->createPost($app, 'hello');

		$response = $this->publish($app, $post);

		self::assertSame(502, $response->status);
		self::assertSame('atproto_publish_failed', $response->decodedBody()['error']['code']);
		self::assertNull($this->publications->findForPost(self::ALICE, $post));
	}

	public function testAContentWarningBecomesALabel(): void
	{
		$app = $this->app();
		$this->http->queueResponseFactory($this->success());

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'chapter one',
				'content_warning' => 'spoilers',
			], JSON_THROW_ON_ERROR),
			userId: self::ALICE,
		));

		self::assertSame(201, $response->status);
		$post = $response->decodedBody()['post']['id'];
		self::assertSame(1, $app->worker->runOnce());

		$request = json_decode($this->http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame(
			[['val' => 'content-warning']],
			$request['record']['labels']['values'],
		);
		self::assertNotNull($this->publications->findForPost(self::ALICE, $post));
	}

	private function app(
		bool $enabled = true,
		?InMemoryAtprotoAccountResolver $accounts = null,
	): Application {
		$accounts ??= new InMemoryAtprotoAccountResolver([
			self::ALICE => $this->account(),
		]);

		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://social.example',
				'atproto.publish' => $enabled ? 'true' : 'false',
			],
			]),
			httpClient: $this->http,
			atprotoAccounts: $accounts,
			atprotoPublications: $this->publications,
		);
	}

	private function account(): AtprotoAccount
	{
		return new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'secret-token');
	}

	private function success(): \Closure
	{
		return static function (string $method, string $url, array $headers, string $body): HttpResponse {
			$request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
			$uri = 'at://' . $request['repo'] . '/' . $request['collection'] . '/' . $request['rkey'];

			return new HttpResponse(200, [], json_encode(['uri' => $uri, 'cid' => 'bafyrei'], JSON_THROW_ON_ERROR));
		};
	}

	private function requestedUri(): string
	{
		$request = json_decode($this->http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);

		return 'at://' . $request['repo'] . '/' . $request['collection'] . '/' . $request['rkey'];
	}

	private function createPost(Application $app, string $content): string
	{
		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content], JSON_THROW_ON_ERROR),
			userId: self::ALICE,
		));

		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $response->decodedBody()['post']['id'];
	}

	private function publish(Application $app, string $postId, ?string $accountDid = null): Response
	{
		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts/' . rawurlencode($postId) . '/atproto',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($accountDid === null ? [] : ['account_did' => $accountDid], JSON_THROW_ON_ERROR),
			userId: self::ALICE,
		));
	}

	private function publicationStatus(Application $app, string $postId, string $userId): Response
	{
		return $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/posts/' . rawurlencode($postId) . '/atproto',
			userId: $userId,
		));
	}
}
