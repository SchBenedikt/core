<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Domain\Federation\PdoFollowingRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Federation\RemoteThreadSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The production composition root a host calls with its own ports.
 *
 * The same assertions the in-memory assembly gets: a post reaches SQL, a
 * follow reaches the peer, and an unconfigured inbox stays honest.
 */
final class ApplicationHostTest extends TestCase
{
	private const BOB = 'https://remote.example/users/bob';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private static RsaKeyPair $key;

	private static RsaKeyPair $remoteKey;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
		self::$remoteKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testForHostUsesSqlRepositoriesAndStillDeliversFollows(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$app = $this->host($database, $http);

		self::assertCount(26, $app->routes->routes());

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'from a host', 'visibility' => 'public'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $post->status);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));

		$follow = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/following',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB, 'inbox' => self::BOB_INBOX], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $follow->status);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . FollowingSchema::TABLE));
		self::assertCount(1, $http->requests);
		self::assertSame(self::BOB_INBOX, $http->requests[0]['url']);

		$collection = $app->handle(new Request('GET', '/@alice/following', ['page' => '1']))->decodedBody();
		self::assertSame(1, $collection['totalItems']);
		self::assertSame([self::BOB], $collection['orderedItems']);

		$timeline = $app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody();
		self::assertSame(1, count($timeline['posts']));
	}

	public function testForHostPersistsASignedCreateAndServesItInTheHomeTimeline(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$following = new PdoFollowingRepository($database);
		$following->insert(new FollowedActor(
			'f1',
			'alice',
			self::BOB,
			self::BOB_INBOX,
			Time::parse('2026-11-11T09:00:00Z'),
		));

		$app = $this->host($database, $http, new InMemoryActorResolver([
			'https://remote.example/users/bob#main-key' => new RemoteActor(
				self::BOB,
				'https://remote.example/users/bob#main-key',
				self::BOB_INBOX,
				self::$remoteKey->publicKeyPem(),
			),
		]));

		$create = [
			'id' => 'https://remote.example/activities/create-1',
			'type' => 'Create',
			'actor' => self::BOB,
			'object' => [
				'id' => 'https://remote.example/notes/1',
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'content' => 'stored in SQL',
				'published' => '2026-11-11T10:01:00Z',
			],
		];

		$response = $app->handle($this->signedInbox($create));

		self::assertSame(202, $response->status);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . RemotePostSchema::TABLE,
		));

		$feed = $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));

		self::assertSame(200, $feed->status);
		self::assertSame('stored in SQL', $feed->decodedBody()['posts'][0]['content']);
		self::assertSame('https://remote.example/activities/create-1', $feed->decodedBody()['posts'][0]['id']);

		$block = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/blocks',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $block->status);
		$blockId = $block->decodedBody()['block']['id'];
		self::assertSame([], $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'))->decodedBody()['posts']);

		self::assertSame(
			204,
			$app->handle(new Request('DELETE', '/api/v1/blocks/' . $blockId, userId: 'alice'))->status,
		);
		self::assertSame(
			'stored in SQL',
			$app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'))->decodedBody()['posts'][0]['content'],
		);
	}

	public function testForHostLeavesTheInboxAt503WithoutAnActorResolver(): void
	{
		$app = $this->host($this->database(), new FakeHttpClient());

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			body: '{"type":"Follow"}',
		));

		self::assertSame(503, $response->status);
		self::assertSame('inbox_not_configured', $response->decodedBody()['error']['code']);

		self::assertSame(200, $app->handle(new Request('GET', '/@alice'))->status);
	}

	private function database(): Connection
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($database);
		FollowerSchema::create($database);
		FollowingSchema::create($database);
		RemotePostSchema::create($database);
		RemoteThreadSchema::create($database);
		BlockSchema::create($database);
		JobSchema::create($database);
		ActivityLogSchema::create($database);
		AtprotoPublicationSchema::create($database);

		return $database;
	}

	private function host(
		Connection $database,
		FakeHttpClient $http,
		?ActorResolver $actorResolver = null,
	): Application {
		return Application::forHost(
			config: new ArrayConfig([
				'social' => [
					'instance.url' => 'https://localhost:8080',
					'instance.name' => 'Aloha Social',
				],
			]),
			database: $database,
			httpClient: $http,
			signingKey: self::$key,
			localActors: new InMemoryActorDirectory(['alice']),
			queue: new PdoJobQueue($database, $this->clock, new RandomIdGenerator()),
			activityLog: new PdoActivityLog($database),
			actorResolver: $actorResolver,
			clock: $this->clock,
		);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function signedInbox(array $activity): Request
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);

		return new Request(
			method: 'POST',
			path: '/inbox',
			headers: HttpSignature::sign(
				self::$remoteKey,
				'https://remote.example/users/bob#main-key',
				'POST',
				'/inbox',
				'localhost',
				$body,
				$this->clock->now(),
			),
			body: $body,
		);
	}
}
