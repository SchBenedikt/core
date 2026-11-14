<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryLocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use PHPUnit\Framework\TestCase;

final class LockedFollowTest extends TestCase
{
	private const ALICE = 'https://localhost:8080/@alice';

	private const BOB_ACTOR = 'https://localhost:8080/@bob';

	private const REMOTE = 'https://remote.example/users/eve';

	private const REMOTE_KEY = 'https://remote.example/users/eve#main-key';

	private const REMOTE_INBOX = 'https://remote.example/users/eve/inbox';

	private static RsaKeyPair $localKey;

	private static RsaKeyPair $remoteKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
		self::$remoteKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
		$this->http = new FakeHttpClient();
	}

	public function testLockedFollowIsPendingUntilTheOwnerAcceptsIt(): void
	{
		$app = $this->app();
		self::assertSame(202, $this->deliver($app, $this->follow(self::ALICE, 'follow-1'))->status);

		self::assertSame(0, $app->followers->count('alice'));
		self::assertSame([], $app->followers->actorIds('alice'));
		self::assertSame(0, $app->queue->pendingCount());
		$pending = $app->followers->pending('alice');
		self::assertCount(1, $pending);
		self::assertSame(self::REMOTE, $pending[0]->actorId);
		self::assertSame('pending', $pending[0]->toArray()['state']);

		$list = $app->handle(new Request('GET', '/api/v1/follow-requests', userId: 'alice'))->decodedBody();
		self::assertCount(1, $list['requests']);
		self::assertSame('pending', $list['requests'][0]['state']);
		self::assertSame(0, count($app->handle(new Request(
			'GET',
			'/api/v1/follow-requests',
			userId: 'bob',
		))->decodedBody()['requests']));

		$foreign = $app->handle(new Request(
			'POST',
			'/api/v1/follow-requests/' . $pending[0]->id . '/accept',
			userId: 'bob',
		));
		self::assertSame(404, $foreign->status);
		self::assertSame('follow_request_not_found', $foreign->decodedBody()['error']['code']);
		self::assertSame(0, $app->followers->count('alice'));

		$accepted = $app->handle(new Request(
			'POST',
			'/api/v1/follow-requests/' . $pending[0]->id . '/accept',
			userId: 'alice',
		));
		self::assertSame(200, $accepted->status);
		self::assertSame('accepted', $accepted->decodedBody()['request']['state']);
		self::assertSame(1, $app->followers->count('alice'));
		self::assertSame([], $app->followers->pending('alice'));
		self::assertSame(1, $app->queue->pendingCount());

		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(1, $this->http->requests);
		$activity = json_decode($this->http->requests[0]['body'], true, 8, JSON_THROW_ON_ERROR);
		self::assertSame('Accept', $activity['type']);
		self::assertSame('https://remote.example/activities/follow-1', $activity['object']['id']);
		self::assertSame(self::REMOTE, $activity['to'][0]);

		self::assertSame(404, $app->handle(new Request(
			'POST',
			'/api/v1/follow-requests/' . $pending[0]->id . '/accept',
			userId: 'alice',
		))->status);
	}

	public function testRejectQueuesARejectAndRemovesTheRequest(): void
	{
		$app = $this->app();
		$this->deliver($app, $this->follow(self::ALICE, 'follow-2'));
		$pending = $app->followers->pending('alice')[0];

		$response = $app->handle(new Request(
			'POST',
			'/api/v1/follow-requests/' . $pending->id . '/reject',
			userId: 'alice',
		));

		self::assertSame(202, $response->status);
		self::assertSame(0, $app->followers->count('alice'));
		self::assertNull($app->followers->find($pending->id));
		self::assertSame(1, $app->queue->pendingCount());
		$app->worker->runOnce();
		$activity = json_decode($this->http->requests[0]['body'], true, 8, JSON_THROW_ON_ERROR);
		self::assertSame('Reject', $activity['type']);
		self::assertSame(404, $app->handle(new Request(
			'POST',
			'/api/v1/follow-requests/' . $pending->id . '/reject',
			userId: 'alice',
		))->status);
	}

	public function testAnUnlockedAccountStillAcceptsImmediately(): void
	{
		$app = $this->app();
		self::assertSame(202, $this->deliver($app, $this->follow(self::BOB_ACTOR, 'follow-3'))->status);

		self::assertSame(1, $app->followers->count('bob'));
		self::assertSame([], $app->followers->pending('bob'));
		self::assertSame(1, $app->queue->pendingCount());
	}

	private function app(): Application
	{
		$profiles = new InMemoryLocalActorProfileProvider([
			'alice' => new LocalActorProfile(
				'alice',
				'Alice',
				'',
				null,
				null,
				[],
				[],
				true,
				false,
				Time::parse('2026-11-14T09:00:00Z'),
			),
		]);

		return Application::inMemory(
			clock: $this->clock,
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::REMOTE_KEY => new RemoteActor(
					self::REMOTE,
					self::REMOTE_KEY,
					self::REMOTE_INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			localProfiles: $profiles,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function follow(string $object, string $id): array
	{
		return [
			'id' => 'https://remote.example/activities/' . $id,
			'type' => 'Follow',
			'actor' => self::REMOTE,
			'object' => $object,
		];
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(Application $app, array $activity): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::REMOTE_KEY,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		return $app->handle(new Request('POST', '/inbox', headers: $headers, body: $body));
	}
}
