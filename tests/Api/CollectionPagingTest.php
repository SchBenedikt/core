<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The public actor collections are paged with the standard ActivityStreams
 * root/page split. The same page metadata must hold for followers, following
 * and the outbox, and a peer must be able to follow `next` and `prev` without
 * knowing the implementation.
 */
final class CollectionPagingTest extends TestCase
{
	private const ACTOR = 'https://localhost:8080/@alice';

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

	public function testFollowersUseAFirstPageWithStableNextAndPrevLinks(): void
	{
		$app = $this->app();

		foreach (['bob', 'carol', 'dave'] as $name) {
			$this->clock->advance(10);
			$app->followers->follow(new FollowActor(
				'alice',
				'https://remote.example/users/' . $name,
				'https://remote.example/users/' . $name . '/inbox',
			));
		}

		$root = $this->get($app, '/@alice/followers', ['limit' => '2']);

		self::assertSame('OrderedCollection', $root['type']);
		self::assertSame(3, $root['totalItems']);
		self::assertSame(self::ACTOR . '/followers?page=1&limit=2', $root['first']);
		self::assertArrayNotHasKey('orderedItems', $root);

		$first = $this->get($app, '/@alice/followers', ['page' => '1', 'limit' => '2']);

		self::assertSame('OrderedCollectionPage', $first['type']);
		self::assertSame(self::ACTOR . '/followers?page=1&limit=2', $first['id']);
		self::assertSame(self::ACTOR . '/followers', $first['partOf']);
		self::assertSame(3, $first['totalItems']);
		self::assertSame(
			['https://remote.example/users/dave', 'https://remote.example/users/carol'],
			$first['orderedItems'],
		);
		self::assertSame(self::ACTOR . '/followers?page=2&limit=2', $first['next']);
		self::assertArrayNotHasKey('prev', $first);

		$second = $this->get($app, '/@alice/followers', ['page' => '2', 'limit' => '2']);

		self::assertSame(['https://remote.example/users/bob'], $second['orderedItems']);
		self::assertSame(self::ACTOR . '/followers?page=1&limit=2', $second['prev']);
		self::assertArrayNotHasKey('next', $second);
	}

	public function testFollowingUsesTheSamePageShape(): void
	{
		$following = new InMemoryFollowingRepository();
		$following->insert(new FollowedActor(
			'f-old',
			'alice',
			'https://remote.example/users/old',
			'https://remote.example/users/old/inbox',
			Time::parse('2026-11-11T10:00:00Z'),
		));
		$following->insert(new FollowedActor(
			'f-mid',
			'alice',
			'https://remote.example/users/mid',
			'https://remote.example/users/mid/inbox',
			Time::parse('2026-11-11T10:01:00Z'),
		));
		$following->insert(new FollowedActor(
			'f-new',
			'alice',
			'https://remote.example/users/new',
			'https://remote.example/users/new/inbox',
			Time::parse('2026-11-11T10:02:00Z'),
		));

		$app = $this->app($following);

		$root = $this->get($app, '/@alice/following', ['limit' => '1']);

		self::assertSame('OrderedCollection', $root['type']);
		self::assertSame(3, $root['totalItems']);
		self::assertSame(self::ACTOR . '/following?page=1&limit=1', $root['first']);

		$first = $this->get($app, '/@alice/following', ['page' => '1', 'limit' => '1']);

		self::assertSame('OrderedCollectionPage', $first['type']);
		self::assertSame(self::ACTOR . '/following', $first['partOf']);
		self::assertSame(['https://remote.example/users/new'], $first['orderedItems']);
		self::assertSame(self::ACTOR . '/following?page=2&limit=1', $first['next']);

		$second = $this->get($app, '/@alice/following', ['page' => '2', 'limit' => '1']);

		self::assertSame(['https://remote.example/users/mid'], $second['orderedItems']);
		self::assertSame(self::ACTOR . '/following?page=1&limit=1', $second['prev']);

		$last = $this->get($app, '/@alice/following', ['page' => '3', 'limit' => '1']);

		self::assertSame(['https://remote.example/users/old'], $last['orderedItems']);
		self::assertSame(self::ACTOR . '/following?page=2&limit=1', $last['prev']);
		self::assertArrayNotHasKey('next', $last);
	}

	public function testOutboxPagesCreateActivitiesNewestFirst(): void
	{
		$app = $this->app();

		foreach (['one', 'two', 'three'] as $content) {
			$this->clock->advance(1);
			$this->publish($app, $content);
		}

		$root = $this->get($app, '/@alice/outbox', ['limit' => '2']);

		self::assertSame('OrderedCollection', $root['type']);
		self::assertSame(3, $root['totalItems']);
		self::assertSame(self::ACTOR . '/outbox?page=1&limit=2', $root['first']);

		$first = $this->get($app, '/@alice/outbox', ['page' => '1', 'limit' => '2']);

		self::assertSame('OrderedCollectionPage', $first['type']);
		self::assertSame(self::ACTOR . '/outbox', $first['partOf']);
		self::assertSame(3, $first['totalItems']);
		self::assertCount(2, $first['orderedItems']);
		self::assertSame(
			['three', 'two'],
			array_map(
				static fn (array $create): string => $create['object']['content'],
				$first['orderedItems'],
			),
		);
		self::assertSame(self::ACTOR . '/outbox?page=2&limit=2', $first['next']);

		$second = $this->get($app, '/@alice/outbox', ['page' => '2', 'limit' => '2']);

		self::assertCount(1, $second['orderedItems']);
		self::assertSame('one', $second['orderedItems'][0]['object']['content']);
		self::assertSame(self::ACTOR . '/outbox?page=1&limit=2', $second['prev']);
		self::assertArrayNotHasKey('next', $second);
	}

	private function app(?FollowingRepository $following = null): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
			followingRepository: $following ?? new InMemoryFollowingRepository(),
		);
	}

	/**
	 * @param array<string, string> $query
	 *
	 * @return array<string, mixed>
	 */
	private function get(Application $app, string $path, array $query = []): array
	{
		$response = $app->handle(new Request('GET', $path, $query));

		self::assertSame(200, $response->status);

		return $response->decodedBody();
	}

	private function publish(Application $app, string $content): void
	{
		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $response->status);
	}
}
