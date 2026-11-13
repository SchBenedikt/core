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
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The local write side of the outgoing follow graph: POST stores the edge and
 * delivers a signed Follow, DELETE delivers an Undo and only then forgets it.
 */
final class FollowingWriteTest extends TestCase
{
	private const ACTOR = 'https://localhost:8080/@alice';

	private const BOB = 'https://remote.example/users/bob';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testFollowStoresAPendingEdgeAndQueuesASignedFollow(): void
	{
		$http = new FakeHttpClient();
		$app = $this->app($http);

		$response = $this->followRequest($app);

		self::assertSame(201, $response->status);
		$body = $response->decodedBody();
		$id = $body['following']['id'];
		self::assertSame(self::BOB, $body['following']['actor']);
		self::assertSame(self::BOB_INBOX, $body['following']['inbox']);
		self::assertSame(FollowState::Pending->value, $body['following']['state']);
		self::assertSame('/api/v1/following/' . $id, $response->header('Location'));

		// The request only persists and queues. The worker is the only path
		// that talks to the peer, so a slow peer cannot hold the API open.
		self::assertCount(0, $http->requests);
		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(1, $http->requests);
		$request = $http->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame(self::BOB_INBOX, $request['url']);
		self::assertSame('application/activity+json', $request['headers']['Content-Type']);
		self::assertArrayHasKey('Signature', $request['headers']);
		self::assertStringContainsString('#main-key', $request['headers']['Signature']);

		$activity = json_decode($request['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('Follow', $activity['type']);
		self::assertSame(self::ACTOR, $activity['actor']);
		self::assertSame(self::BOB, $activity['object']);
		self::assertSame([self::BOB], $activity['to']);
		self::assertSame(
			'https://localhost:8080/api/v1/following/' . $id . '/follow',
			$activity['id'],
		);

		$this->assertSignatureCovers($request);

		// A delivered Follow is still pending until the peer accepts it.
		self::assertSame(FollowState::Pending->value, $app->following->find('alice', self::BOB)?->state->value);
		self::assertSame(0, $this->collection($app)['totalItems']);

		$stored = $app->following->find('alice', self::BOB);
		self::assertNotNull($stored);
		$app->following->respondToFollow(
			$stored->followActivityId ?? '',
			self::BOB,
			FollowState::Accepted,
		);

		self::assertSame(1, $this->collection($app)['totalItems']);
		self::assertSame([self::BOB], $this->collection($app)['orderedItems']);
	}

	public function testFollowIsIdempotentAndDoesNotQueueTwice(): void
	{
		$http = new FakeHttpClient();
		$app = $this->app($http);

		self::assertSame(201, $this->followRequest($app)->status);
		self::assertSame(200, $this->followRequest($app)->status);

		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(1, $http->requests);
		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testUnfollowQueuesAnUndoAndRemovesThePendingEdgeWithoutAStaleFollow(): void
	{
		$http = new FakeHttpClient();
		$app = $this->app($http);

		$created = $this->followRequest($app)->decodedBody()['following'];
		$response = $this->unfollowRequest($app, $created['id']);

		self::assertSame(204, $response->status);
		self::assertSame(0, $app->following->count('alice'));
		self::assertSame(2, $app->queue->pendingCount());

		// Both jobs are due. The Follow job sees the removed row and skips;
		// only the Undo reaches the peer, even if it runs second.
		self::assertSame(2, $app->worker->runOnce());
		self::assertCount(1, $http->requests);

		$undo = json_decode($http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('Undo', $undo['type']);
		self::assertSame(self::ACTOR, $undo['actor']);
		self::assertSame(self::BOB, $undo['object']['object']);
		self::assertSame('Follow', $undo['object']['type']);
		self::assertSame(
			'https://localhost:8080/api/v1/following/' . $created['id'] . '/follow',
			$undo['object']['id'],
		);
		self::assertSame([self::BOB], $undo['to']);

		$this->assertSignatureCovers($http->requests[0]);

		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testAFailedFollowStaysPendingAndIsRetriedByTheWorker(): void
	{
		$http = new FakeHttpClient();
		$app = $this->app($http);

		$http->queueResponse(new HttpResponse(503));
		$response = $this->followRequest($app);

		self::assertSame(201, $response->status);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(FollowState::Pending->value, $app->following->find('alice', self::BOB)?->state->value);

		// The same request is idempotent while the retry is pending.
		self::assertSame(200, $this->followRequest($app)->status);
		self::assertSame(1, $app->queue->pendingCount());

		$this->clock->advance(30);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(0, $app->queue->pendingCount());
		self::assertCount(2, $http->requests);
		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testAFailedUndoIsRetriedAfterTheEdgeWasAlreadyRemoved(): void
	{
		$http = new FakeHttpClient();
		$app = $this->app($http);

		$created = $this->followRequest($app)->decodedBody()['following'];
		$app->worker->runOnce();

		$http->queueResponse(new HttpResponse(503));
		$response = $this->unfollowRequest($app, $created['id']);

		self::assertSame(204, $response->status);
		self::assertSame(0, $app->following->count('alice'));
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(1, $app->queue->pendingCount());

		$this->clock->advance(30);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(0, $app->queue->pendingCount());
		self::assertCount(3, $http->requests);
		self::assertSame('Undo', json_decode($http->requests[2]['body'], true, 64, JSON_THROW_ON_ERROR)['type']);
	}

	public function testFollowRejectsAHandWithoutAnAbsoluteActorAndInbox(): void
	{
		$app = $this->app();

		$response = $this->followRequestWith($app, ['actor' => 'bob', 'inbox' => 'not-a-url']);

		self::assertSame(422, $response->status);
		$error = $response->decodedBody()['error'];
		self::assertSame('validation_failed', $error['code']);
		self::assertArrayHasKey('actor', $error['fields']);
		self::assertArrayHasKey('inbox', $error['fields']);
		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testFollowAndUnfollowRequireAuthentication(): void
	{
		$app = $this->app();

		self::assertSame(401, $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/following',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB, 'inbox' => self::BOB_INBOX], JSON_THROW_ON_ERROR),
		))->status);

		self::assertSame(401, $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/following/some-id',
		))->status);
	}

	public function testFollowIsUnavailableWithoutADeliveryClient(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
			followingRepository: new InMemoryFollowingRepository(),
		);

		$response = $this->followRequest($app);

		self::assertSame(503, $response->status);
		self::assertSame('federation_not_configured', $response->decodedBody()['error']['code']);
		self::assertSame(0, $this->collection($app)['totalItems']);
	}

	public function testFollowingAnotherAccountsEdgeIsANotFound(): void
	{
		$http = new FakeHttpClient();
		$app = Application::inMemory(
			clock: $this->clock,
			httpClient: $http,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			followingRepository: new InMemoryFollowingRepository(),
		);

		$created = $this->followRequest($app)->decodedBody()['following'];
		$stored = $app->following->find('alice', self::BOB);
		self::assertNotNull($stored);
		$app->following->respondToFollow($stored->followActivityId ?? '', self::BOB, FollowState::Accepted);

		$response = $this->unfollowRequest($app, $created['id'], 'bob');

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
		self::assertSame(1, $this->collection($app)['totalItems']);
		self::assertSame(1, $app->following->count('alice'));

		self::assertSame(404, $this->unfollowRequest($app, 'unknown', 'alice')->status);
	}

	private function app(?FakeHttpClient $http = null): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			httpClient: $http ?? new FakeHttpClient(),
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			followingRepository: new InMemoryFollowingRepository(),
		);
	}

	private function followRequest(Application $app, string $userId = 'alice'): Response
	{
		return $this->followRequestWith($app, ['actor' => self::BOB, 'inbox' => self::BOB_INBOX], $userId);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function followRequestWith(Application $app, array $body, string $userId = 'alice'): Response
	{
		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/following',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: $userId,
		));
	}

	private function unfollowRequest(Application $app, string $id, string $userId = 'alice'): Response
	{
		return $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/following/' . rawurlencode($id),
			userId: $userId,
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collection(Application $app, string $handle = 'alice'): array
	{
		return $app->handle(new Request('GET', '/@' . $handle . '/following', ['page' => '1']))->decodedBody();
	}

	/**
	 * @param array{method: string, url: string, headers: array<string, string>, body: string} $request
	 */
	private function assertSignatureCovers(array $request): void
	{
		$path = (string) parse_url($request['url'], PHP_URL_PATH);

		HttpSignature::verify(
			self::$localKey->publicKeyPem(),
			$request['method'],
			$path,
			array_change_key_case($request['headers'], CASE_LOWER),
			$request['body'],
			300,
			$this->clock->now(),
		);
	}
}
