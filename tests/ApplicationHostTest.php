<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
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

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
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

		self::assertCount(18, $app->routes->routes());

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

		$collection = $app->handle(new Request('GET', '/@alice/following'))->decodedBody();
		self::assertSame(1, $collection['totalItems']);
		self::assertSame([self::BOB], $collection['orderedItems']);

		$timeline = $app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody();
		self::assertSame(1, count($timeline['posts']));
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
		JobSchema::create($database);
		ActivityLogSchema::create($database);

		return $database;
	}

	private function host(Connection $database, FakeHttpClient $http): Application
	{
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
			clock: $this->clock,
		);
	}
}
