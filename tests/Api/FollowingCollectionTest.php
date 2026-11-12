<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The public following collection: the actors a local account follows,
 * read from the outgoing follow repository.
 */
final class FollowingCollectionTest extends TestCase
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

	public function testTheActorDocumentAdvertisesTheFollowingCollection(): void
	{
		$document = $this->app()->handle(new Request('GET', '/@alice'))->decodedBody();

		self::assertSame(self::ACTOR . '/following', $document['following']);

		$route = Routes::manifest()->find('actors.following');
		self::assertNotNull($route);
		self::assertSame(
			parse_url($document['following'], PHP_URL_PATH),
			str_replace('{handle}', 'alice', $route->path),
		);
	}

	public function testTheFollowingCollectionIsEmptyByDefault(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@alice/following'));

		self::assertSame(200, $response->status);
		self::assertSame('application/activity+json; charset=utf-8', $response->header('Content-Type'));

		$collection = $response->decodedBody();

		self::assertSame(ActivityFactory::CONTEXT, $collection['@context']);
		self::assertSame(self::ACTOR . '/following', $collection['id']);
		self::assertSame('OrderedCollection', $collection['type']);
		self::assertSame(0, $collection['totalItems']);
		self::assertSame([], $collection['orderedItems']);
	}

	public function testTheCollectionIdIsExactlyTheFollowingTheActorDocumentAdvertises(): void
	{
		$app = $this->app();

		$document = $app->handle(new Request('GET', '/@alice'))->decodedBody();
		$collection = $app->handle(new Request('GET', '/@alice/following'))->decodedBody();

		self::assertSame($document['following'], $collection['id']);
	}

	public function testAFollowedActorAppearsAndUnfollowRemovesIt(): void
	{
		$app = $this->app();

		$app->following->follow('alice', self::BOB, self::BOB_INBOX);

		$collection = $this->collection($app);
		self::assertSame(1, $collection['totalItems']);
		self::assertSame([self::BOB], $collection['orderedItems']);

		self::assertTrue($app->following->unfollow('alice', self::BOB));

		$collection = $this->collection($app);
		self::assertSame(0, $collection['totalItems']);
		self::assertSame([], $collection['orderedItems']);
	}

	public function testFollowingTwiceDoesNotDuplicateTheEntry(): void
	{
		$app = $this->app();

		$app->following->follow('alice', self::BOB, self::BOB_INBOX);
		$app->following->follow('alice', self::BOB, self::BOB_INBOX);

		self::assertSame(1, $this->collection($app)['totalItems']);
	}

	public function testFollowingIsScopedToTheAccount(): void
	{
		$app = $this->app();

		$app->following->follow('alice', self::BOB, self::BOB_INBOX);

		$bob = $app->handle(new Request('GET', '/@bob/following'));
		self::assertSame(404, $bob->status);

		$app = Application::inMemory(
			clock: $this->clock,
			httpClient: new FakeHttpClient(),
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
		);
		$app->following->follow('alice', self::BOB, self::BOB_INBOX);

		self::assertSame(0, $this->collection($app, 'bob')['totalItems']);
		self::assertSame(1, $this->collection($app, 'alice')['totalItems']);
	}

	public function testAnInjectedFollowingRepositoryFeedsTheCollection(): void
	{
		$repository = new InMemoryFollowingRepository();
		$repository->insert(new FollowedActor(
			'f1',
			'alice',
			self::BOB,
			self::BOB_INBOX,
			Time::parse('2026-11-11T10:00:00Z'),
		));

		$app = Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
			followingRepository: $repository,
		);

		self::assertSame([self::BOB], $this->collection($app)['orderedItems']);
	}

	public function testAFollowRequiresAnAbsoluteActorUrl(): void
	{
		$this->expectException(ValidationException::class);

		$this->app()->following->follow('alice', 'bob', self::BOB_INBOX);
	}

	public function testAnUnknownHandleIsAClean404(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@mallory/following'));

		self::assertSame(404, $response->status);
		self::assertSame('actor_not_found', $response->decodedBody()['error']['code']);
		self::assertStringNotContainsString('mallory', (string) json_encode($response->decodedBody()));
	}

	public function testTheFollowingCollectionIsDisabledWithoutAKey(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			localActors: new InMemoryActorDirectory(['alice']),
		);

		$response = $app->handle(new Request('GET', '/@alice/following'));

		self::assertSame(503, $response->status);
		self::assertSame('actor_not_configured', $response->decodedBody()['error']['code']);
	}

	public function testTheFollowingCollectionIsDisabledWithoutADirectory(): void
	{
		$app = Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
		);

		$response = $app->handle(new Request('GET', '/@alice/following'));

		self::assertSame(503, $response->status);
		self::assertSame('actor_not_configured', $response->decodedBody()['error']['code']);
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			httpClient: new FakeHttpClient(),
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
			followingRepository: new InMemoryFollowingRepository(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collection(Application $app, string $handle = 'alice'): array
	{
		return $app->handle(new Request('GET', '/@' . $handle . '/following'))->decodedBody();
	}
}
