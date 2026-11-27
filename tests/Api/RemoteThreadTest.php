<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\LocalHttpClient;
use AlohaSocial\Core\Tests\Support\RecordingLogger;
use AlohaSocial\Core\Tests\Support\RemoteMediaServer;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

use function rawurlencode;

/**
 * The full thread path over a real local HTTP server:
 * authenticated request -> followed-target check -> guarded object fetch ->
 * bounded ancestor walk and direct replies -> persisted cache -> JSON.
 */
final class RemoteThreadTest extends TestCase
{
	private const ALICE = 'alice';

	private const BOB = 'https://remote.example/users/bob';

	private const MALLORY = 'https://remote.example/users/mallory';

	private const TARGET = 'https://remote.example/notes/target';

	private const TARGET_ACTIVITY = 'https://remote.example/activities/create-target';

	private static RemoteMediaServer $server;

	private FrozenClock $clock;

	private InMemoryFollowingRepository $following;

	private InMemoryBlockRepository $blocks;

	private InMemoryRemotePostRepository $remotePosts;

	private InMemoryRemoteThreadRepository $threads;

	private LocalHttpClient $http;

	private RecordingLogger $logger;

	public static function setUpBeforeClass(): void
	{
		self::$server = new RemoteMediaServer();
	}

	public static function tearDownAfterClass(): void
	{
		self::$server->stop();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T12:00:00Z'));
		$this->following = new InMemoryFollowingRepository();
		$this->following->insert(new FollowedActor(
			'f1',
			self::ALICE,
			self::BOB,
			'https://remote.example/users/bob/inbox',
			Time::parse('2026-11-11T09:00:00Z'),
		));

		$this->blocks = new InMemoryBlockRepository();
		$this->blocks->insert(new Block(
			'b-mallory',
			self::ALICE,
			self::MALLORY,
			Time::parse('2026-11-11T09:30:00Z'),
		));

		$this->remotePosts = new InMemoryRemotePostRepository();
		$this->threads = new InMemoryRemoteThreadRepository();
		$this->remotePosts->insert($this->remotePost(
			self::TARGET_ACTIVITY,
			self::TARGET,
			self::BOB,
		));

		$this->http = new LocalHttpClient(self::$server->baseUrl);
		$this->logger = new RecordingLogger();
	}

	public function testThreadReturnsAncestorsAndDirectReplies(): void
	{
		$response = $this->show($this->app());

		self::assertSame(200, $response->status);
		$thread = $response->decodedBody()['thread'];
		self::assertSame(self::TARGET, $thread['post']['object_id']);
		self::assertSame('target content', $thread['post']['content']);
		self::assertSame(
			['https://remote.example/notes/root', 'https://remote.example/notes/parent'],
			array_column($thread['ancestors'], 'object_id'),
		);
		self::assertSame(
			['https://remote.example/notes/reply-a', 'https://remote.example/notes/reply-b'],
			array_column($thread['replies'], 'object_id'),
		);

		self::assertNotNull($this->threads->find('https://remote.example/notes/parent'));
		self::assertNotNull($this->threads->find('https://remote.example/notes/reply-a'));
		self::assertNull(
			$this->threads->find('https://remote.example/notes/reply-blocked'),
			'A blocked reply must not be cached.',
		);
		self::assertNull(
			$this->threads->find('https://remote.example/notes/reply-nested'),
			'A nested reply is not part of the direct-reply view.',
		);
		self::assertContains('thread_invalid_document', $this->logger->reasons());
	}

	public function testRepeatedThreadRequestsUpsertWithoutDuplicates(): void
	{
		$app = $this->app();

		self::assertSame(200, $this->show($app)->status);
		self::assertSame(200, $this->show($app)->status);

		self::assertCount(2, $this->threads->children(self::TARGET, 10));
		self::assertSame(
			['https://remote.example/notes/reply-a', 'https://remote.example/notes/reply-b'],
			array_column($this->show($app)->decodedBody()['thread']['replies'], 'object_id'),
		);
	}

	public function testThreadDepthAndReplyLimitAreBounded(): void
	{
		$response = $this->show($this->app(new ArrayConfig([
			'social' => [
				'instance.url' => 'https://social.example',
				'thread.max_depth' => '1',
				'thread.max_replies' => '1',
			],
		])));

		self::assertSame(200, $response->status);
		$thread = $response->decodedBody()['thread'];
		self::assertSame(
			['https://remote.example/notes/parent'],
			array_column($thread['ancestors'], 'object_id'),
		);
		self::assertSame(
			['https://remote.example/notes/reply-a'],
			array_column($thread['replies'], 'object_id'),
		);
	}

	public function testZeroDepthAndReplyLimitsReturnOnlyTheTarget(): void
	{
		$response = $this->show($this->app(new ArrayConfig([
			'social' => [
				'instance.url' => 'https://social.example',
				'thread.max_depth' => '0',
				'thread.max_replies' => '0',
			],
		])));

		self::assertSame(200, $response->status);
		$thread = $response->decodedBody()['thread'];
		self::assertSame([], $thread['ancestors']);
		self::assertSame([], $thread['replies']);
		self::assertNull($this->threads->find('https://remote.example/notes/parent'));
		self::assertNull($this->threads->find('https://remote.example/notes/reply-a'));
	}

	public function testThreadRequiresAuthentication(): void
	{
		$response = $this->app()->handle($this->request(self::TARGET, null));

		self::assertSame(401, $response->status);
		self::assertSame('authentication_required', $response->decodedBody()['error']['code']);
	}

	public function testUnknownUnfollowedDeletedAndBlockedTargetsAreNotFound(): void
	{
		$app = $this->app();

		self::assertSame(404, $this->show($app, 'https://remote.example/activities/create-missing')->status);

		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-carol',
			'https://remote.example/notes/carol',
			'https://remote.example/users/carol',
		));
		self::assertSame(404, $this->show($app, 'https://remote.example/activities/create-carol')->status);

		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/create-deleted',
			objectId: 'https://remote.example/notes/deleted',
			actorId: self::BOB,
			kind: RemotePostKind::Post,
			content: 'deleted',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T10:00:00Z'),
			deletedAt: Time::parse('2026-11-11T11:00:00Z'),
		));
		self::assertSame(404, $this->show($app, 'https://remote.example/activities/create-deleted')->status);

		$this->blocks->insert(new Block(
			'b-bob',
			self::ALICE,
			self::BOB,
			Time::parse('2026-11-11T09:45:00Z'),
		));
		self::assertSame(404, $this->show($app)->status);
	}

	public function testThreadWithoutABoundedHttpClientIsNotConfigured(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				'social' => ['instance.url' => 'https://social.example'],
			]),
			followingRepository: $this->following,
			remotePostRepository: $this->remotePosts,
			remoteThreadRepository: $this->threads,
			blockRepository: $this->blocks,
			logger: $this->logger,
		);

		$response = $this->show($app);

		self::assertSame(503, $response->status);
		self::assertSame('thread_not_configured', $response->decodedBody()['error']['code']);
	}

	public function testDisabledThreadFetchingIsA503NotAFakeEmptyThread(): void
	{
		$response = $this->show($this->app(new ArrayConfig([
			'social' => [
				'instance.url' => 'https://social.example',
				'thread.fetch' => 'false',
			],
		])));

		self::assertSame(503, $response->status);
		self::assertSame('thread_fetch_disabled', $response->decodedBody()['error']['code']);
	}

	public function testInvalidTargetDocumentIsA502AndLeavesTheCacheEmpty(): void
	{
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-invalid-target',
			'https://remote.example/notes/invalid-target',
			self::BOB,
		));

		$response = $this->show($this->app(), 'https://remote.example/activities/create-invalid-target');

		self::assertSame(502, $response->status);
		self::assertSame('thread_fetch_failed', $response->decodedBody()['error']['code']);
		self::assertNull($this->threads->find('https://remote.example/notes/invalid-target'));
	}

	public function testBlockedAncestorIsSkipped(): void
	{
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-blocked-target',
			'https://remote.example/notes/blocked-target',
			self::BOB,
		));

		$response = $this->show($this->app(), 'https://remote.example/activities/create-blocked-target');

		self::assertSame(200, $response->status);
		self::assertSame([], $response->decodedBody()['thread']['ancestors']);
		self::assertSame('blocked target', $response->decodedBody()['thread']['post']['content']);
	}

	public function testSsrfAncestorIsSkippedAndLogged(): void
	{
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-ssrf-target',
			'https://remote.example/notes/ssrf-target',
			self::BOB,
		));

		$response = $this->show($this->app(), 'https://remote.example/activities/create-ssrf-target');

		self::assertSame(200, $response->status);
		self::assertSame([], $response->decodedBody()['thread']['ancestors']);
		self::assertContains('thread_ssrf_blocked', $this->logger->reasons());
	}

	public function testTooLargeAndSlowTargetsFailWithStableReasons(): void
	{
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-too-large',
			'https://remote.example/notes/too-large',
			self::BOB,
		));
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-slow',
			'https://remote.example/notes/slow',
			self::BOB,
		));

		$app = $this->app(new ArrayConfig([
			'social' => [
				'instance.url' => 'https://social.example',
				'thread.max_bytes' => '1000',
				'thread.timeout_seconds' => '1',
			],
		]));

		self::assertSame(502, $this->show($app, 'https://remote.example/activities/create-too-large')->status);
		self::assertSame(502, $this->show($app, 'https://remote.example/activities/create-slow')->status);
		self::assertContains('thread_too_large', $this->logger->reasons());
		self::assertContains('thread_timeout', $this->logger->reasons());
	}

	public function testAPublicAudienceTargetOpensWithoutTheFollowButContextStaysFiltered(): void
	{
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-public-target',
			'https://remote.example/notes/public-target',
			self::BOB,
			Visibility::Public,
		));

		$response = $this->app()->handle($this->request(
			'https://remote.example/activities/create-public-target',
			'carol',
		));

		self::assertSame(200, $response->status);
		$thread = $response->decodedBody()['thread'];
		self::assertSame('https://remote.example/notes/public-target', $thread['post']['object_id']);

		// The followers-only ancestor ends the visible chain and the
		// followers-only reply is filtered for a reader without the follow.
		self::assertSame([], $thread['ancestors']);
		self::assertSame(
			['https://remote.example/notes/public-reply'],
			array_column($thread['replies'], 'object_id'),
		);
	}

	public function testAFollowersAudienceTargetStillRequiresTheFollow(): void
	{
		$this->remotePosts->insert($this->remotePost(
			'https://remote.example/activities/create-followers-target',
			'https://remote.example/notes/followers-target',
			self::BOB,
			Visibility::Followers,
		));

		self::assertSame(404, $this->app()->handle($this->request(
			'https://remote.example/activities/create-followers-target',
			'carol',
		))->status);

		// The follower still sees the full context, restricted nodes included.
		$response = $this->show($this->app(), 'https://remote.example/activities/create-followers-target');
		self::assertSame(200, $response->status);
		self::assertSame('followers target', $response->decodedBody()['thread']['post']['content']);
	}

	private function app(?ArrayConfig $config = null): Application
	{
		$config ??= new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example'],
		]);

		return Application::inMemory(
			clock: $this->clock,
			config: $config,
			httpClient: $this->http,
			followingRepository: $this->following,
			remotePostRepository: $this->remotePosts,
			remoteThreadRepository: $this->threads,
			blockRepository: $this->blocks,
			hostResolver: new TestHostResolver([
				'remote.example' => ['93.184.216.34'],
				'thread.remote.example' => ['93.184.216.34'],
			]),
			logger: $this->logger,
		);
	}

	private function show(Application $app, string $activityId = self::TARGET_ACTIVITY): \AlohaSocial\Core\Http\Response
	{
		return $app->handle($this->request($activityId, self::ALICE));
	}

	private function request(?string $activityId, ?string $userId = self::ALICE): Request
	{
		$path = $activityId === null
			? '/api/v1/remote-posts/'
			: '/api/v1/remote-posts/' . rawurlencode($activityId) . '/thread';

		return new Request('GET', $path, userId: $userId);
	}

	private function remotePost(
		string $activityId,
		string $objectId,
		string $actorId,
		?Visibility $audience = null,
	): RemotePost {
		return new RemotePost(
			activityId: $activityId,
			objectId: $objectId,
			actorId: $actorId,
			kind: RemotePostKind::Post,
			content: 'stored target',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T10:02:00Z'),
			audience: $audience,
		);
	}
}
