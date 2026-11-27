<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use AlohaSocial\Core\Tests\Support\RemoteActors;
use PHPUnit\Framework\TestCase;

/**
 * The complete outgoing follow lifecycle: queued Follow, signed Accept/Reject,
 * duplicate responses, and responses that arrive after an Unfollow.
 */
final class FollowingLifecycleTest extends TestCase
{
	private const ACTOR = 'https://localhost:8080/@alice';

	private const BOB = 'https://remote.example/users/bob';

	private const BOB_KEY = 'https://remote.example/users/bob#main-key';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private const MALLORY = 'https://mallory.example/users/eve';

	private const MALLORY_KEY = 'https://mallory.example/users/eve#main-key';

	private static RsaKeyPair $localKey;

	private static RsaKeyPair $bobKey;

	private static RsaKeyPair $malloryKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
		self::$bobKey = RsaKeyPair::generate();
		self::$malloryKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->http = new FakeHttpClient();
	}

	public function testAcceptMovesPendingToAcceptedAndPublishesTheFollowOnce(): void
	{
		$app = $this->app();
		$created = $this->follow($app);
		$followId = $this->pendingFollowId($app);

		self::assertSame(FollowState::Pending->value, $created['state']);
		self::assertSame(0, $this->collection($app)['totalItems']);

		$response = $this->respond($app, 'Accept', 'accept-1', $followId);

		self::assertSame(202, $response->status);
		self::assertSame(FollowState::Accepted->value, $app->following->find('alice', self::BOB)?->state->value);
		self::assertSame(1, $this->collection($app)['totalItems']);

		// A duplicate of the exact same activity is a replay. It is still a
		// successful retry and must not produce a second transition.
		$duplicate = $this->respond($app, 'Accept', 'accept-1', $followId);

		self::assertSame(202, $duplicate->status);
		self::assertSame(FollowState::Accepted->value, $app->following->find('alice', self::BOB)?->state->value);
		self::assertSame(1, $this->collection($app)['totalItems']);
	}

	public function testAnAcceptThatNamesOnlyTheFollowIdIsApplied(): void
	{
		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		$activity = [
			'id' => 'https://remote.example/activities/accept-id-only',
			'type' => 'Accept',
			'actor' => self::BOB,
			'object' => $followId,
		];

		self::assertSame(202, $this->deliver($app, $activity, self::BOB_KEY, self::$bobKey)->status);
		self::assertSame(FollowState::Accepted->value, $app->following->find('alice', self::BOB)?->state->value);
	}

	public function testRejectMovesPendingToRejectedAndIsIdempotent(): void
	{
		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		self::assertSame(202, $this->respond($app, 'Reject', 'reject-1', $followId)->status);
		self::assertSame(FollowState::Rejected->value, $app->following->find('alice', self::BOB)?->state->value);
		self::assertSame(0, $this->collection($app)['totalItems']);

		self::assertSame(202, $this->respond($app, 'Reject', 'reject-1', $followId)->status);
		self::assertSame(FollowState::Rejected->value, $app->following->find('alice', self::BOB)?->state->value);
		self::assertSame(0, $app->following->count('alice'));
	}

	public function testAcceptAfterRejectDoesNotResurrectTheEdge(): void
	{
		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		self::assertSame(202, $this->respond($app, 'Reject', 'reject-1', $followId)->status);
		self::assertSame(202, $this->respond($app, 'Accept', 'accept-late', $followId)->status);

		self::assertSame(FollowState::Rejected->value, $app->following->find('alice', self::BOB)?->state->value);
		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testAcceptAfterUnfollowIsANoOp(): void
	{
		$app = $this->app();
		$created = $this->follow($app);
		$followId = $this->pendingFollowId($app);

		self::assertSame(204, $this->unfollow($app, $created['id'])->status);
		self::assertNull($app->following->find('alice', self::BOB));

		self::assertSame(202, $this->respond($app, 'Accept', 'accept-late', $followId)->status);
		self::assertNull($app->following->find('alice', self::BOB));
		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testOnlyTheFollowedActorMayAcceptOrReject(): void
	{
		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		$response = $this->respond($app, 'Accept', 'accept-foreign', $followId, self::MALLORY_KEY, self::MALLORY);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertSame(FollowState::Pending->value, $app->following->find('alice', self::BOB)?->state->value);
	}

	public function testAnEmbeddedFollowForAnotherActorIsRefused(): void
	{
		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		$body = $this->responseBody('Accept', 'accept-wrong-object', $followId, self::BOB, self::MALLORY);
		$response = $this->deliver($app, $body, self::BOB_KEY, self::$bobKey);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertSame(FollowState::Pending->value, $app->following->find('alice', self::BOB)?->state->value);
	}

	public function testCompetingTransitionsOnlyApplyWhileTheRowIsPending(): void
	{
		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		self::assertTrue($app->following->respondToFollow($followId, self::BOB, FollowState::Accepted));
		self::assertFalse($app->following->respondToFollow($followId, self::BOB, FollowState::Rejected));
		self::assertSame(FollowState::Accepted->value, $app->following->find('alice', self::BOB)?->state->value);

		$app = $this->app();
		$this->follow($app);
		$followId = $this->pendingFollowId($app);

		self::assertTrue($app->following->respondToFollow($followId, self::BOB, FollowState::Rejected));
		self::assertFalse($app->following->respondToFollow($followId, self::BOB, FollowState::Accepted));
		self::assertSame(FollowState::Rejected->value, $app->following->find('alice', self::BOB)?->state->value);
	}

	public function testARefollowAfterRejectUsesANewFollowIdSoTheOldResponseCannotMatchIt(): void
	{
		$app = $this->app();
		$this->follow($app);
		$oldFollowId = $this->pendingFollowId($app);
		self::assertSame(202, $this->respond($app, 'Reject', 'reject-1', $oldFollowId)->status);

		$new = $this->follow($app);
		$newFollowId = $this->pendingFollowId($app);

		self::assertNotSame($oldFollowId, $newFollowId);
		self::assertSame(FollowState::Pending->value, $new['state']);

		self::assertSame(202, $this->respond($app, 'Reject', 'reject-old', $oldFollowId, self::BOB_KEY, self::BOB)->status);
		self::assertSame(FollowState::Pending->value, $app->following->find('alice', self::BOB)?->state->value);
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
			]]),
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::BOB_KEY => new RemoteActor(
					self::BOB,
					self::BOB_KEY,
					self::BOB_INBOX,
					self::$bobKey->publicKeyPem(),
				),
				self::MALLORY_KEY => new RemoteActor(
					self::MALLORY,
					self::MALLORY_KEY,
					self::MALLORY . '/inbox',
					self::$malloryKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			localActors: new InMemoryActorDirectory(['alice']),
			followingRepository: new InMemoryFollowingRepository(),
			remoteActors: RemoteActors::fetcher(RemoteActors::actor(self::BOB, self::BOB_INBOX)),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function follow(Application $app): array
	{
		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/following',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB, 'inbox' => self::BOB_INBOX], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $response->status);

		return $response->decodedBody()['following'];
	}

	private function pendingFollowId(Application $app): string
	{
		$stored = $app->following->find('alice', self::BOB);
		self::assertNotNull($stored);
		self::assertNotNull($stored->followActivityId);

		return $stored->followActivityId;
	}

	private function unfollow(Application $app, string $id): Response
	{
		return $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/following/' . rawurlencode($id),
			userId: 'alice',
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function responseBody(
		string $type,
		string $id,
		string $followId,
		string $actor,
		string $followObject = self::BOB,
	): array {
		return [
			'id' => 'https://remote.example/activities/' . $id,
			'type' => $type,
			'actor' => $actor,
			'object' => [
				'id' => $followId,
				'type' => 'Follow',
				'actor' => self::ACTOR,
				'object' => $followObject,
			],
		];
	}

	private function respond(
		Application $app,
		string $type,
		string $id,
		string $followId,
		string $keyId = self::BOB_KEY,
		string $actor = self::BOB,
	): Response {
		$body = $this->responseBody($type, $id, $followId, $actor);

		$key = $keyId === self::MALLORY_KEY ? self::$malloryKey : self::$bobKey;

		return $this->deliver($app, $body, $keyId, $key);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(Application $app, array $activity, string $keyId, RsaKeyPair $key): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			$key,
			$keyId,
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
	 * @return array<string, mixed>
	 */
	private function collection(Application $app): array
	{
		return $app->handle(new Request('GET', '/@alice/following', ['page' => '1']))->decodedBody();
	}
}
