<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\CachingActorResolver;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class CachingActorResolverTest extends TestCase
{
	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	public function testAHitDoesNotCallTheInnerResolverAgain(): void
	{
		$inner = $this->inner();
		$cache = new CachingActorResolver($inner, $this->clock());

		self::assertSame('https://remote.example/users/bob', $cache->resolveByKeyId(self::KEY_ID)->actorId);
		self::assertSame('https://remote.example/users/bob', $cache->resolveByKeyId(self::KEY_ID)->actorId);

		self::assertSame(1, $inner->calls);
		self::assertSame(1, $cache->size());
	}

	public function testAnExpiredEntryIsResolvedAgain(): void
	{
		$inner = $this->inner();
		$clock = $this->clock();
		$cache = new CachingActorResolver($inner, $clock, ttlSeconds: 10);

		$cache->resolveByKeyId(self::KEY_ID);
		$cache->resolveByKeyId(self::KEY_ID);
		self::assertSame(1, $inner->calls);

		$clock->advance(11);

		$cache->resolveByKeyId(self::KEY_ID);
		self::assertSame(2, $inner->calls);
	}

	public function testAFailedResolutionIsNotCached(): void
	{
		$inner = new class implements ActorResolver {
			public int $calls = 0;

			public function resolveByKeyId(string $keyId): RemoteActor
			{
				$this->calls++;

				throw new UnknownActorException($keyId);
			}
		};
		$cache = new CachingActorResolver($inner, $this->clock());

		foreach ([1, 2] as $attempt) {
			try {
				$cache->resolveByKeyId(self::KEY_ID);
				self::fail('The resolver should have refused the key.');
			} catch (UnknownActorException) {
				self::assertSame($attempt, $inner->calls);
			}
		}

		self::assertSame(0, $cache->size());
	}

	public function testTheCacheIsBounded(): void
	{
		$actors = [
			'https://a.example/users/one#main-key' => $this->actor('https://a.example/users/one'),
			'https://b.example/users/two#main-key' => $this->actor('https://b.example/users/two'),
			'https://c.example/users/three#main-key' => $this->actor('https://c.example/users/three'),
		];
		$inner = new class($actors) implements ActorResolver {
			public int $calls = 0;

			/**
			 * @param array<string, RemoteActor> $actors
			 */
			public function __construct(
				private readonly array $actors,
			) {
			}

			public function resolveByKeyId(string $keyId): RemoteActor
			{
				$this->calls++;

				return $this->actors[$keyId] ?? throw new UnknownActorException($keyId);
			}
		};
		$cache = new CachingActorResolver($inner, $this->clock(), maxEntries: 1);

		$cache->resolveByKeyId('https://a.example/users/one#main-key');
		$cache->resolveByKeyId('https://b.example/users/two#main-key');
		$cache->resolveByKeyId('https://a.example/users/one#main-key');

		self::assertSame(3, $inner->calls);
		self::assertSame(1, $cache->size());
	}

	public function testClearDropsEveryEntry(): void
	{
		$inner = $this->inner();
		$cache = new CachingActorResolver($inner, $this->clock());

		$cache->resolveByKeyId(self::KEY_ID);
		self::assertSame(1, $cache->size());

		$cache->clear();
		self::assertSame(0, $cache->size());

		$cache->resolveByKeyId(self::KEY_ID);
		self::assertSame(2, $inner->calls);
	}

	private function clock(): FrozenClock
	{
		return new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	private function inner(): ActorResolver
	{
		return new class($this->actor('https://remote.example/users/bob')) implements ActorResolver {
			public int $calls = 0;

			public function __construct(
				private readonly RemoteActor $actor,
			) {
			}

			public function resolveByKeyId(string $keyId): RemoteActor
			{
				$this->calls++;

				return $this->actor;
			}
		};
	}

	private function actor(string $actorId): RemoteActor
	{
		return new RemoteActor(
			$actorId,
			$actorId . '#main-key',
			$actorId . '/inbox',
			'public key',
		);
	}
}
