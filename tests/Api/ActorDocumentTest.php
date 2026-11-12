<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\ActorDocumentService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use PHPUnit\Framework\TestCase;

/**
 * The public face of a local account: its actor document and the followers
 * collection. Both read the same repository the inbox writes.
 */
final class ActorDocumentTest extends TestCase
{
	private const ACTOR = 'https://localhost:8080/@alice';

	private const KEY_ID = 'https://localhost:8080/@alice#main-key';

	private const BOB = 'https://remote.example/users/bob';

	private const BOB_KEY = 'https://remote.example/users/bob#main-key';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private static RsaKeyPair $localKey;

	private static RsaKeyPair $remoteKey;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
		self::$remoteKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testTheDocumentDescribesEverythingAPeerNeeds(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@alice'));

		self::assertSame(200, $response->status);
		self::assertSame('application/activity+json; charset=utf-8', $response->header('Content-Type'));

		$document = $response->decodedBody();

		self::assertSame([ActivityFactory::CONTEXT, ActorDocumentService::CONTEXT_SECURITY], $document['@context']);
		self::assertSame(self::ACTOR, $document['id']);
		self::assertSame('Person', $document['type']);
		self::assertSame('alice', $document['preferredUsername']);
		self::assertSame('alice', $document['name']);
		self::assertSame('https://localhost:8080/@alice/outbox', $document['outbox']);
		self::assertSame('https://localhost:8080/@alice/followers', $document['followers']);
		self::assertSame('https://localhost:8080/@alice/following', $document['following']);
		self::assertSame(self::KEY_ID, $document['publicKey']['id']);
		self::assertSame(self::ACTOR, $document['publicKey']['owner']);
		self::assertSame(self::$localKey->publicKeyPem(), $document['publicKey']['publicKeyPem']);

		// The published inbox is the route the manifest actually serves, and
		// the key id is exactly the one the inbox resolves on a signed request.
		$inboxRoute = Routes::manifest()->find('inbox');
		self::assertNotNull($inboxRoute);
		self::assertSame('https://localhost:8080' . $inboxRoute->path, $document['inbox']);
		self::assertSame($document['id'] . '#main-key', $document['publicKey']['id']);
	}

	public function testAPeerCanFetchTheDocumentAndSignAnAcceptedFollow(): void
	{
		$document = $this->app()->handle(new Request('GET', '/@alice'))->decodedBody();

		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode($document, JSON_THROW_ON_ERROR)));

		$app = Application::inMemory(
			clock: $this->clock,
			httpClient: $http,
			signingKey: self::$localKey,
			actorResolver: new HttpActorResolver($http),
			localActors: new InMemoryActorDirectory(['alice']),
		);

		$body = json_encode([
			'id' => 'https://localhost:8080/activities/follow-1',
			'type' => 'Follow',
			'actor' => $document['id'],
			'object' => $document['id'],
		], JSON_THROW_ON_ERROR);

		$headers = HttpSignature::sign(
			self::$localKey,
			$document['publicKey']['id'],
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		$response = $app->handle(new Request('POST', '/inbox', headers: $headers, body: $body));

		self::assertSame(202, $response->status);
		self::assertSame(1, $app->followers->count('alice'));
	}

	public function testTheFollowersCollectionIsEmptyByDefault(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@alice/followers'));

		self::assertSame(200, $response->status);
		self::assertSame('application/activity+json; charset=utf-8', $response->header('Content-Type'));

		$collection = $response->decodedBody();

		self::assertSame(ActivityFactory::CONTEXT, $collection['@context']);
		self::assertSame('https://localhost:8080/@alice/followers', $collection['id']);
		self::assertSame('OrderedCollection', $collection['type']);
		self::assertSame(0, $collection['totalItems']);
		self::assertSame(self::ACTOR . '/followers?page=1', $collection['first']);
		self::assertArrayNotHasKey('orderedItems', $collection);
	}

	public function testAnAcceptedFollowAppearsInTheCollection(): void
	{
		$app = $this->appWithInbox();

		self::assertSame(202, $this->deliver($app, $this->follow())->status);

		$collection = $this->collection($app);

		self::assertSame(1, $collection['totalItems']);
		self::assertSame([self::BOB], $collection['orderedItems']);
	}

	public function testUndoRemovesTheFollowerFromTheCollection(): void
	{
		$app = $this->appWithInbox();

		self::assertSame(202, $this->deliver($app, $this->follow())->status);
		self::assertSame(202, $this->deliver($app, $this->undo())->status);

		$collection = $this->collection($app);

		self::assertSame(0, $collection['totalItems']);
		self::assertSame([], $collection['orderedItems']);
	}

	public function testAnUnknownHandleIsAClean404(): void
	{
		$app = $this->app();

		$actor = $app->handle(new Request('GET', '/@mallory'));
		self::assertSame(404, $actor->status);
		self::assertSame('actor_not_found', $actor->decodedBody()['error']['code']);
		self::assertStringNotContainsString('mallory', (string) json_encode($actor->decodedBody()));

		$followers = $app->handle(new Request('GET', '/@mallory/followers'));
		self::assertSame(404, $followers->status);
		self::assertSame('actor_not_found', $followers->decodedBody()['error']['code']);
	}

	public function testTheActorRoutesAreDisabledWithoutAKey(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			localActors: new InMemoryActorDirectory(['alice']),
		);

		$actor = $app->handle(new Request('GET', '/@alice'));
		self::assertSame(503, $actor->status);
		self::assertSame('actor_not_configured', $actor->decodedBody()['error']['code']);

		$followers = $app->handle(new Request('GET', '/@alice/followers'));
		self::assertSame(503, $followers->status);
		self::assertSame('actor_not_configured', $followers->decodedBody()['error']['code']);
	}

	public function testTheActorRoutesAreDisabledWithoutADirectory(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
		);

		self::assertSame(503, $app->handle(new Request('GET', '/@alice'))->status);
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
		);
	}

	private function appWithInbox(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			httpClient: new FakeHttpClient(),
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::BOB_KEY => new RemoteActor(
					self::BOB,
					self::BOB_KEY,
					self::BOB_INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			localActors: new InMemoryActorDirectory(['alice']),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collection(Application $app): array
	{
		return $app->handle(new Request('GET', '/@alice/followers', ['page' => '1']))->decodedBody();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function follow(): array
	{
		return [
			'id' => 'https://remote.example/activities/follow-1',
			'type' => 'Follow',
			'actor' => self::BOB,
			'object' => self::ACTOR,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function undo(): array
	{
		return [
			'id' => 'https://remote.example/activities/undo-1',
			'type' => 'Undo',
			'actor' => self::BOB,
			'object' => $this->follow(),
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
			self::BOB_KEY,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		return $app->handle(new Request('POST', '/inbox', headers: $headers, body: $body));
	}
}
