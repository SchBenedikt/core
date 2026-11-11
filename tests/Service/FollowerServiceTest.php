<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FollowerServiceTest extends TestCase
{
	private InMemoryFollowerRepository $repository;

	private FrozenClock $clock;

	private FollowerService $service;

	protected function setUp(): void
	{
		$this->repository = new InMemoryFollowerRepository();
		$this->clock = new FrozenClock(new DateTimeImmutable('2026-11-11T10:00:00.987654Z'));
		$this->service = new FollowerService($this->repository, $this->clock, new RandomIdGenerator());
	}

	public function testFollowStoresTheFollowerWithSecondPrecision(): void
	{
		$result = $this->service->follow(new FollowActor(
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/users/bob/inbox',
		));

		self::assertTrue($result->created);
		self::assertSame('alice', $result->follower->localUserId);
		self::assertSame('2026-11-11T10:00:00Z', $result->follower->toArray()['followed_at']);
		self::assertCount(1, $this->service->followers('alice'));
	}

	public function testRepeatedFollowKeepsTheIdAndUpdatesTheInbox(): void
	{
		$first = $this->service->follow(new FollowActor(
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/users/bob/inbox',
		));

		$this->clock->advance(60);

		$second = $this->service->follow(new FollowActor(
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/inbox-v2',
		));

		self::assertFalse($second->created);
		self::assertSame($first->follower->id, $second->follower->id);
		self::assertSame('https://remote.example/inbox-v2', $second->follower->inboxUrl);
		self::assertSame(1, $this->service->count('alice'));
	}

	public function testTheSameActorCanFollowDifferentAccounts(): void
	{
		$this->service->follow(new FollowActor('alice', 'https://remote.example/users/bob', 'https://remote.example/bob'));
		$this->service->follow(new FollowActor('carol', 'https://remote.example/users/bob', 'https://remote.example/bob'));

		self::assertSame(1, $this->service->count('alice'));
		self::assertSame(1, $this->service->count('carol'));
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function invalidFollows(): iterable
	{
		yield 'relative actor' => ['/users/bob', 'https://remote.example/inbox', 'actor'];
		yield 'actor without scheme' => ['remote.example/users/bob', 'https://remote.example/inbox', 'actor'];
		yield 'actor with a foreign scheme' => ['ftp://remote.example/users/bob', 'https://remote.example/inbox', 'actor'];
		yield 'inbox that is not a URL' => ['https://remote.example/users/bob', 'not a url', 'inbox'];
	}

	#[DataProvider('invalidFollows')]
	public function testInvalidUrlsAreRefusedPerField(string $actor, string $inbox, string $field): void
	{
		try {
			$this->service->follow(new FollowActor('alice', $actor, $inbox));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey($field, $exception->fields());
		}
	}

	public function testAnOverlongActorIsRefused(): void
	{
		$actor = 'https://remote.example/users/' . str_repeat('a', FollowerService::MAX_ACTOR_LENGTH);

		try {
			$this->service->follow(new FollowActor('alice', $actor, 'https://remote.example/inbox'));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('actor', $exception->fields());
		}
	}

	public function testUnfollowOnlyRemovesTheCallersFollower(): void
	{
		$follower = $this->service->follow(new FollowActor(
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/inbox',
		))->follower;

		self::assertFalse($this->service->unfollow('carol', $follower->id));
		self::assertSame(1, $this->service->count('alice'));

		self::assertTrue($this->service->unfollow('alice', $follower->id));
		self::assertSame(0, $this->service->count('alice'));
	}

	public function testFollowersArePagedNewestFirst(): void
	{
		foreach (['bob', 'carol', 'dave'] as $index => $name) {
			$this->clock->advance(10 * ($index + 1));
			$this->service->follow(new FollowActor(
				'alice',
				'https://remote.example/users/' . $name,
				'https://remote.example/users/' . $name . '/inbox',
			));
		}

		$page = $this->service->followers('alice', 2);

		self::assertSame(
			['https://remote.example/users/dave', 'https://remote.example/users/carol'],
			array_map(static fn ($follower): string => $follower->actorId, $page),
		);
	}

	public function testLimitIsClamped(): void
	{
		foreach (['bob', 'carol', 'dave'] as $index => $name) {
			$this->clock->advance(10 * ($index + 1));
			$this->service->follow(new FollowActor(
				'alice',
				'https://remote.example/users/' . $name,
				'https://remote.example/users/' . $name . '/inbox',
			));
		}

		// A limit below one still returns one row; a limit above the maximum
		// is capped, not rejected.
		self::assertCount(1, $this->service->followers('alice', 0));
		self::assertCount(3, $this->service->followers('alice', 500));
	}

	public function testAnInvalidCursorIsRefused(): void
	{
		$this->expectException(InvalidCursorException::class);

		$this->service->followers('alice', 20, 'yesterday');
	}

	public function testInboxesAreReturnedForDelivery(): void
	{
		$this->service->follow(new FollowActor('alice', 'https://a.example/users/one', 'https://a.example/inbox'));
		$this->service->follow(new FollowActor('alice', 'https://b.example/users/two', 'https://b.example/inbox'));
		$this->service->follow(new FollowActor('carol', 'https://c.example/users/three', 'https://c.example/inbox'));

		self::assertSame(
			['https://a.example/inbox', 'https://b.example/inbox'],
			$this->repository->inboxesFor('alice'),
		);
	}
}
