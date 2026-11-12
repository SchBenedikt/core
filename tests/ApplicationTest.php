<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\FixedInboxResolver;
use PHPUnit\Framework\TestCase;

/**
 * The core driven the way a host drives it: a request in, a response out,
 * then the worker that finishes the side effects.
 */
final class ApplicationTest extends TestCase
{
	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testWritesAndReadsAPostEndToEnd(): void
	{
		$app = $this->app();

		$created = $this->post($app, 'alice', 'hello world');

		self::assertSame(201, $created->status);
		self::assertSame('/api/v1/posts/' . $created->decodedBody()['post']['id'], $created->header('Location'));

		$id = $created->decodedBody()['post']['id'];

		$feed = $app->handle(new Request('GET', '/api/v1/timelines/public'));
		self::assertSame(200, $feed->status);
		self::assertSame([$id], array_column($feed->decodedBody()['posts'], 'id'));

		$single = $app->handle(new Request('GET', '/api/v1/posts/' . $id));
		self::assertSame('hello world', $single->decodedBody()['post']['content']);
	}

	public function testAnonymousWritesAreRefused(): void
	{
		$response = $this->app()->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			body: '{"content":"nope"}',
		));

		self::assertSame(401, $response->status);
	}

	public function testBrokenJsonIsABadRequest(): void
	{
		$response = $this->app()->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			body: '{',
			userId: 'alice',
		));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_json', $response->decodedBody()['error']['code']);
	}

	public function testValidationErrorsNameTheField(): void
	{
		$response = $this->post($this->app(), 'alice', '   ');

		self::assertSame(422, $response->status);
		self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		self::assertArrayHasKey('content', $response->decodedBody()['error']['fields']);
	}

	public function testTheAuthorDeletesThePost(): void
	{
		$app = $this->app();
		$id = $this->post($app, 'alice', 'goodbye')->decodedBody()['post']['id'];

		$deleted = $app->handle(new Request('DELETE', '/api/v1/posts/' . $id, userId: 'alice'));

		self::assertSame(204, $deleted->status);
		self::assertSame('', $deleted->body);

		self::assertSame(404, $app->handle(new Request('GET', '/api/v1/posts/' . $id))->status);
		self::assertSame([], $app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);
	}

	public function testSomebodyElseCannotDeleteThePost(): void
	{
		$app = $this->app();
		$id = $this->post($app, 'alice', 'mine')->decodedBody()['post']['id'];

		$response = $app->handle(new Request('DELETE', '/api/v1/posts/' . $id, userId: 'bob'));

		self::assertSame(403, $response->status);
		self::assertSame('forbidden', $response->decodedBody()['error']['code']);
	}

	public function testTheHomeTimelineIsPrivate(): void
	{
		$app = $this->app();
		$this->post($app, 'alice', 'for everyone');

		self::assertSame(401, $app->handle(new Request('GET', '/api/v1/timelines/home'))->status);
		self::assertSame(200, $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'bob'))->status);
	}

	public function testReportsTheInstanceDescription(): void
	{
		$response = $this->app()->handle(new Request('GET', '/api/v1/instance'));

		self::assertSame('Aloha Social', $response->decodedBody()['instance']['name']);
		self::assertSame('v1', $response->decodedBody()['instance']['api']);
	}

	public function testDeliversOneSignedActivityPerInbox(): void
	{
		$http = new FakeHttpClient();
		$key = RsaKeyPair::generate();
		$app = $this->app($http, $key, new FixedInboxResolver([
			'https://remote.example/inbox',
			'https://other.example/users/bob/inbox',
		]));

		$this->post($app, 'alice', 'federate me');

		self::assertSame(2, $app->queue->pendingCount());
		self::assertSame(2, $app->worker->runOnce(10));
		self::assertSame(0, $app->queue->pendingCount());
		self::assertCount(2, $http->requests);

		$urls = [];
		$hosts = [];

		foreach ($http->requests as $request) {
			self::assertSame('POST', $request['method']);
			self::assertArrayHasKey('Signature', $request['headers']);
			self::assertArrayHasKey('Date', $request['headers']);
			self::assertSame(HttpSignature::digest($request['body']), $request['headers']['Digest']);

			$activity = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
			self::assertSame('Create', $activity['type']);
			self::assertSame('Note', $activity['object']['type']);
			self::assertStringContainsString('@alice#main-key', $request['headers']['Signature']);

			$urls[] = $request['url'];
			$hosts[] = $request['headers']['Host'];
		}

		// The queue hands jobs out by availability and id, so two deliveries
		// of the same post may arrive in either order.
		sort($urls);
		sort($hosts);

		self::assertSame(['https://other.example/users/bob/inbox', 'https://remote.example/inbox'], $urls);
		self::assertSame(['other.example', 'remote.example'], $hosts);
	}

	public function testTheSignatureOnTheWireVerifies(): void
	{
		$http = new FakeHttpClient();
		$key = RsaKeyPair::generate();
		$app = $this->app($http, $key, new FixedInboxResolver(['https://remote.example/inbox']));

		$this->post($app, 'alice', 'signed');
		$app->worker->runOnce();

		$request = $http->requests[0];
		$headers = array_change_key_case($request['headers'], CASE_LOWER);

		HttpSignature::verify(
			$key->publicKeyPem(),
			'POST',
			'/inbox',
			$headers,
			$request['body'],
			300,
			$this->clock->now(),
		);

		self::assertTrue(true);
	}

	public function testAFailedDeliveryIsRetriedWithBackoff(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(503));
		$app = $this->app($http, RsaKeyPair::generate(), new FixedInboxResolver([
			'https://remote.example/inbox',
		]));

		$this->post($app, 'alice', 'eventually');
		$app->worker->runOnce();

		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(0, $app->queue->deadLetterCount());

		// Not due yet.
		self::assertSame(0, $app->worker->runOnce());

		$this->clock->advance(InMemoryJobQueue::BASE_BACKOFF_SECONDS);
		self::assertSame(1, $app->worker->runOnce());

		self::assertSame(0, $app->queue->pendingCount());
		self::assertCount(2, $http->requests);
	}

	public function testFollowingIsIdempotentAndVisible(): void
	{
		$app = $this->app();

		$created = $this->follow($app, 'alice', 'https://remote.example/users/bob', 'https://remote.example/users/bob/inbox');
		self::assertSame(201, $created->status);
		$id = $created->decodedBody()['follower']['id'];
		self::assertSame('/api/v1/followers/' . $id, $created->header('Location'));

		$this->clock->advance(60);
		$repeated = $this->follow($app, 'alice', 'https://remote.example/users/bob', 'https://remote.example/inbox-v2');
		self::assertSame(200, $repeated->status);
		self::assertSame($id, $repeated->decodedBody()['follower']['id']);
		self::assertSame('https://remote.example/inbox-v2', $repeated->decodedBody()['follower']['inbox']);

		self::assertSame(401, $app->handle(new Request('GET', '/api/v1/followers'))->status);

		$list = $app->handle(new Request('GET', '/api/v1/followers', userId: 'alice'));
		self::assertSame(200, $list->status);
		self::assertSame(1, $list->decodedBody()['total']);
		self::assertSame([$id], array_column($list->decodedBody()['followers'], 'id'));

		self::assertSame(
			204,
			$app->handle(new Request('DELETE', '/api/v1/followers/' . $id, userId: 'alice'))->status,
		);
		self::assertSame(
			0,
			$app->handle(new Request('GET', '/api/v1/followers', userId: 'alice'))->decodedBody()['total'],
		);
		self::assertSame(
			404,
			$app->handle(new Request('DELETE', '/api/v1/followers/' . $id, userId: 'alice'))->status,
		);
	}

	public function testFollowersOfOtherAccountsAreInvisible(): void
	{
		$app = $this->app();
		$id = $this->follow($app, 'alice', 'https://remote.example/users/bob', 'https://remote.example/inbox')
			->decodedBody()['follower']['id'];

		self::assertSame(0, $app->handle(new Request('GET', '/api/v1/followers', userId: 'bob'))->decodedBody()['total']);
		self::assertSame(
			404,
			$app->handle(new Request('DELETE', '/api/v1/followers/' . $id, userId: 'bob'))->status,
		);
		self::assertSame(1, $app->handle(new Request('GET', '/api/v1/followers', userId: 'alice'))->decodedBody()['total']);
	}

	public function testAMalformedFollowIsRejected(): void
	{
		$response = $this->app()->handle(new Request(
			method: 'POST',
			path: '/api/v1/followers',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => 'bob', 'inbox' => 'https://remote.example/inbox'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('actor', $response->decodedBody()['error']['fields']);

		$response = $this->app()->handle(new Request(
			method: 'POST',
			path: '/api/v1/followers',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'actor' => 'https://remote.example/users/bob',
				'inbox' => 'https://remote.example/users/bob/inbox',
				'shared_inbox' => 'not a url',
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('shared_inbox', $response->decodedBody()['error']['fields']);
	}

	public function testDeliveryReachesTheRealFollowGraph(): void
	{
		$http = new FakeHttpClient();
		$key = RsaKeyPair::generate();
		$app = $this->app($http, $key);

		$this->follow($app, 'alice', 'https://remote.example/users/bob', 'https://remote.example/users/bob/inbox');
		$this->post($app, 'alice', 'to my followers');

		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(0, $app->queue->pendingCount());

		self::assertCount(1, $http->requests);
		self::assertSame('https://remote.example/users/bob/inbox', $http->requests[0]['url']);

		$activity = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('Create', $activity['type']);
		self::assertSame('to my followers', $activity['object']['content']);
	}

	public function testSharedInboxesBatchDeliveryForFollowersOnOneServer(): void
	{
		$http = new FakeHttpClient();
		$key = RsaKeyPair::generate();
		$app = $this->app($http, $key);

		$this->follow(
			$app,
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/users/bob/inbox',
			'https://remote.example/inbox/shared',
		);
		$this->follow(
			$app,
			'alice',
			'https://remote.example/users/carol',
			'https://remote.example/users/carol/inbox',
			'https://remote.example/inbox/shared',
		);

		$this->post($app, 'alice', 'batched');

		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(1, $http->requests);
		self::assertSame('https://remote.example/inbox/shared', $http->requests[0]['url']);

		HttpSignature::verify(
			$key->publicKeyPem(),
			'POST',
			'/inbox/shared',
			array_change_key_case($http->requests[0]['headers'], CASE_LOWER),
			$http->requests[0]['body'],
			300,
			$this->clock->now(),
		);

		self::assertTrue(true);
	}

	public function testUnfollowingStopsDelivery(): void
	{
		$http = new FakeHttpClient();
		$app = $this->app($http, RsaKeyPair::generate());

		$id = $this->follow($app, 'alice', 'https://remote.example/users/bob', 'https://remote.example/inbox')
			->decodedBody()['follower']['id'];
		$app->handle(new Request('DELETE', '/api/v1/followers/' . $id, userId: 'alice'));

		$this->post($app, 'alice', 'nobody left');

		self::assertSame(0, $app->queue->pendingCount());
		self::assertSame(0, $app->worker->runOnce());
		self::assertCount(0, $http->requests);
	}

	public function testAnUnconfiguredInstanceRefusesToFakeDelivery(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		Application::inMemory(
			clock: $this->clock,
			inboxResolver: new FixedInboxResolver(['https://remote.example/inbox']),
		);
	}

	private function app(
		?FakeHttpClient $http = null,
		?RsaKeyPair $key = null,
		?FixedInboxResolver $inboxes = null,
	): Application {
		return Application::inMemory(
			clock: $this->clock,
			httpClient: $http,
			signingKey: $key,
			inboxResolver: $inboxes,
		);
	}

	private function follow(
		Application $app,
		string $user,
		string $actor,
		string $inbox,
		?string $sharedInbox = null,
	): Response {
		$body = ['actor' => $actor, 'inbox' => $inbox];
		if ($sharedInbox !== null) {
			$body['shared_inbox'] = $sharedInbox;
		}

		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/followers',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: $user,
		));
	}

	private function post(Application $app, string $user, string $content): Response
	{
		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content], JSON_THROW_ON_ERROR),
			userId: $user,
		));
	}
}
