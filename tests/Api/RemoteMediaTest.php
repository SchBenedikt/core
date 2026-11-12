<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use AlohaSocial\Core\Tests\Support\FailingRemotePostLinkRepository;
use AlohaSocial\Core\Tests\Support\LocalHttpClient;
use AlohaSocial\Core\Tests\Support\RecordingLogger;
use AlohaSocial\Core\Tests\Support\RemoteMediaServer;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

use function array_column;
use function bin2hex;
use function getimagesizefromstring;
use function in_array;
use function is_array;
use function json_encode;
use function mkdir;
use function random_bytes;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The full inbound path over a real local HTTP server:
 * signed Inbox -> guarded fetch -> existing image validation/storage ->
 * remote-post link -> home timeline with local URLs and alt text.
 */
final class RemoteMediaTest extends TestCase
{
	private const BOB = 'https://remote.example/users/bob';

	private const BOB_KEY = 'https://remote.example/users/bob#main-key';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private const MEDIA_HOST = 'http://media.remote.example';

	private static RemoteMediaServer $server;

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private InMemoryFollowingRepository $following;

	private InMemoryAttachmentRepository $attachments;

	private LocalBlobStore $blobs;

	private LocalHttpClient $http;

	private RecordingLogger $logger;

	private string $directory;

	public static function setUpBeforeClass(): void
	{
		self::$server = new RemoteMediaServer();
		self::$remoteKey = RsaKeyPair::generate();
		self::$localKey = RsaKeyPair::generate();
	}

	public static function tearDownAfterClass(): void
	{
		self::$server->stop();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->following = new InMemoryFollowingRepository();
		$this->following->insert(new FollowedActor(
			'f1',
			'alice',
			self::BOB,
			self::BOB_INBOX,
			Time::parse('2026-11-11T09:00:00Z'),
		));

		$this->directory = sys_get_temp_dir() . '/alohasocial-remote-blobs-' . bin2hex(random_bytes(8));
		mkdir($this->directory, 0o770, true);
		$this->attachments = new InMemoryAttachmentRepository();
		$this->blobs = new LocalBlobStore($this->directory);
		$this->http = new LocalHttpClient(self::$server->baseUrl);
		$this->logger = new RecordingLogger();
	}

	protected function tearDown(): void
	{
		$this->remove($this->directory);
	}

	public function testAnIncomingPostStoresOriginalThumbnailAltTextAndLocalUrl(): void
	{
		$app = $this->app();

		$response = $this->deliver($app, $this->create(
			'https://remote.example/activities/create-image',
			'https://remote.example/notes/image',
			'with a remote picture',
			'2026-11-11T10:01:00Z',
			[[
				'type' => 'Image',
				'url' => self::MEDIA_HOST . '/image.png',
				'mediaType' => 'image/png',
				'name' => 'A blue test image',
			]],
		));

		self::assertSame(202, $response->status);

		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $posts);
		self::assertSame('with a remote picture', $posts[0]['content']);
		self::assertCount(1, $posts[0]['attachments']);

		$shape = $posts[0]['attachments'][0];
		self::assertSame('A blue test image', $shape['alt_text']);
		self::assertSame('image/png', $shape['mime_type']);
		self::assertStringStartsWith('https://social.example/api/v1/media/', $shape['url']);
		self::assertStringEndsWith('?variant=thumbnail', $shape['thumbnail_url']);

		$stored = $this->attachments->find($shape['id']);
		self::assertNotNull($stored);
		self::assertSame(self::MEDIA_HOST . '/image.png', $stored->sourceUrl);
		self::assertCount(2, $this->blobs->list('media/' . $shape['id']));
		self::assertCount(1, $this->http->requests);

		$original = $app->handle(new Request('GET', $shape['url']));
		self::assertSame(200, $original->status);
		self::assertSame('image/png', $original->header('Content-Type'));
		self::assertSame(self::$server->fixtureBytes, $original->body);

		$thumbnail = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/media/' . $shape['id'],
			query: ['variant' => 'thumbnail'],
		));
		self::assertSame(200, $thumbnail->status);
		$thumbnailInfo = getimagesizefromstring($thumbnail->body);
		self::assertIsArray($thumbnailInfo);
		self::assertLessThanOrEqual(640, $thumbnailInfo[0]);
		self::assertLessThanOrEqual(640, $thumbnailInfo[1]);
	}

	public function testRepeatedRemoteUrlsReuseOneLocalAttachment(): void
	{
		$app = $this->app();

		foreach (['one', 'two'] as $suffix) {
			$response = $this->deliver($app, $this->create(
				'https://remote.example/activities/create-' . $suffix,
				'https://remote.example/notes/' . $suffix,
				'post ' . $suffix,
				'2026-11-11T10:01:00Z',
				[[
					'type' => 'Image',
					'url' => self::MEDIA_HOST . '/image.png',
					'mediaType' => 'image/png',
					'name' => 'same picture',
				]],
			));
			self::assertSame(202, $response->status);
		}

		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(2, $posts);
		self::assertSame($posts[0]['attachments'][0]['id'], $posts[1]['attachments'][0]['id']);
		self::assertCount(1, $this->attachments->findMany(array_column($posts[0]['attachments'], 'id')));
		self::assertCount(1, $this->http->requests);
		self::assertCount(2, $this->blobs->list('media/' . $posts[0]['attachments'][0]['id']));
	}

	public function testBadRemoteMediaLeavesThePostAvailableWithoutAttachments(): void
	{
		$app = $this->app(new ArrayConfig([
			'social' => [
				'instance.url' => 'https://social.example',
				'media.remote_max_bytes' => '1000',
				'media.remote_timeout_seconds' => '1',
			],
		]));

		$cases = [
			'ssrf' => [self::$server->baseUrl . '/image.png', 'remote_media_ssrf_blocked'],
			'redirect-private' => [self::MEDIA_HOST . '/redirect-private', 'remote_media_ssrf_blocked'],
			'too-large' => [self::MEDIA_HOST . '/too-large', 'remote_media_too_large'],
			'corrupt' => [self::MEDIA_HOST . '/corrupt', 'remote_media_invalid_image'],
			'declared-mismatch' => [self::MEDIA_HOST . '/image.png', 'remote_media_invalid_image', 'image/jpeg'],
			'wrong-type' => [self::MEDIA_HOST . '/wrong-type', 'remote_media_invalid_image'],
			'missing' => [self::MEDIA_HOST . '/missing', 'remote_media_http_error'],
			'timeout' => [self::MEDIA_HOST . '/slow', 'remote_media_timeout'],
		];

		$expectedReasons = [];
		$index = 0;
		foreach ($cases as $name => $case) {
			$url = $case[0];
			$reason = $case[1];
			$declared = $case[2] ?? 'image/png';
			++$index;
			$response = $this->deliver($app, $this->create(
				'https://remote.example/activities/create-bad-' . $name,
				'https://remote.example/notes/bad-' . $name,
				'still available ' . $name,
				'2026-11-11T10:0' . $index . ':00Z',
				[[
					'type' => 'Image',
					'url' => $url,
					'mediaType' => $declared,
					'name' => 'bad image',
				]],
			));

			self::assertSame(202, $response->status, $name);
			$expectedReasons[] = $reason;
		}

		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(count($cases), $posts);
		foreach ($posts as $post) {
			self::assertArrayNotHasKey('attachments', $post);
			self::assertStringStartsWith('still available ', $post['content']);
		}

		self::assertSame(0, $this->attachments->count());
		self::assertSame([], $this->blobs->list('media'));
		foreach ($expectedReasons as $reason) {
			self::assertTrue(
				in_array($reason, $this->logger->reasons(), true),
				'Expected a ' . $reason . ' log entry, got: ' . implode(', ', $this->logger->reasons()),
			);
		}
	}

	public function testAFailedLinkRemovesNewlyCreatedRowsAndBlobs(): void
	{
		$app = $this->app(null, new FailingRemotePostLinkRepository(new InMemoryRemotePostRepository()));

		$response = $this->deliver($app, $this->create(
			'https://remote.example/activities/create-link-failure',
			'https://remote.example/notes/link-failure',
			'post survives a link failure',
			'2026-11-11T10:01:00Z',
			[[
				'type' => 'Image',
				'url' => self::MEDIA_HOST . '/image.png',
				'mediaType' => 'image/png',
				'name' => 'cleaned up',
			]],
		));

		self::assertSame(202, $response->status);
		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $posts);
		self::assertSame('post survives a link failure', $posts[0]['content']);
		self::assertArrayNotHasKey('attachments', $posts[0]);
		self::assertSame(0, $this->attachments->count());
		self::assertSame([], $this->blobs->list('media'));
		self::assertContains('remote_media_link_failed', $this->logger->reasons());
	}

	public function testOnlyFourAttachmentsAreImportedPerPost(): void
	{
		$app = $this->app();
		$attachments = [];
		for ($index = 1; $index <= 5; ++$index) {
			$attachments[] = [
				'type' => 'Image',
				'url' => self::MEDIA_HOST . '/image.png?slot=' . $index,
				'mediaType' => 'image/png',
				'name' => 'slot ' . $index,
			];
		}

		$response = $this->deliver($app, $this->create(
			'https://remote.example/activities/create-many',
			'https://remote.example/notes/many',
			'five images',
			'2026-11-11T10:01:00Z',
			$attachments,
		));

		self::assertSame(202, $response->status);
		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $posts);
		self::assertCount(4, $posts[0]['attachments']);
		self::assertSame(4, $this->attachments->count());
		self::assertContains('remote_media_attachment_limit', $this->logger->reasons());
	}

	private function app(
		?ArrayConfig $config = null,
		?RemotePostRepository $remotePosts = null,
	): Application {
		$config ??= new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example'],
		]);

		return Application::inMemory(
			clock: $this->clock,
			config: $config,
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::BOB_KEY => new RemoteActor(
					self::BOB,
					self::BOB_KEY,
					self::BOB_INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			followingRepository: $this->following,
			remotePostRepository: $remotePosts,
			attachmentRepository: $this->attachments,
			blobStore: $this->blobs,
			hostResolver: new TestHostResolver([
				'media.remote.example' => ['93.184.216.34'],
			]),
			logger: $this->logger,
		);
	}

	private function feed(Application $app): Response
	{
		return $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(Application $app, array $activity): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::BOB_KEY,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		return $app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $body,
		));
	}

	/**
	 * @param list<array<string, mixed>> $attachments
	 *
	 * @return array<string, mixed>
	 */
	private function create(
		string $activityId,
		string $noteId,
		string $content,
		string $publishedAt,
		array $attachments = [],
	): array {
		$object = [
			'id' => $noteId,
			'type' => 'Note',
			'attributedTo' => self::BOB,
			'content' => $content,
			'published' => $publishedAt,
		];
		if ($attachments !== []) {
			$object['attachments'] = $attachments;
		}

		return [
			'id' => $activityId,
			'type' => 'Create',
			'actor' => self::BOB,
			'published' => $publishedAt,
			'object' => $object,
		];
	}

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $path . '/' . $entry;
			if (is_dir($full)) {
				$this->remove($full);
			} else {
				unlink($full);
			}
		}

		rmdir($path);
	}
}
