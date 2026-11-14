<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryLocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ActorProfileUpdateServiceTest extends TestCase
{
	private static RsaKeyPair $key;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
	}

	public function testTheUpdateKeepsTheActorIdentityAndCarriesTheNewProfile(): void
	{
		$app = $this->app();
		$app->followers->follow(new FollowActor(
			'alice',
			'https://remote.example/users/bob',
			'https://remote.example/users/bob/inbox',
		));

		$activity = $app->profileUpdates?->activity('alice');

		self::assertNotNull($activity);
		self::assertSame('Update', $activity['type']);
		self::assertSame('https://localhost:8080/@alice', $activity['actor']);
		self::assertSame('https://localhost:8080/@alice', $activity['object']['id']);
		self::assertSame('https://localhost:8080/@alice#main-key', $activity['object']['publicKey']['id']);
		self::assertSame('Alice Updated', $activity['object']['name']);
		self::assertSame(['https://localhost:8080/@alice/followers'], $activity['to']);
		self::assertStringContainsString('#updates/', $activity['id']);

		self::assertSame(1, $app->profileUpdates?->publish('alice'));
		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(1, $this->http->requests);
	}

	public function testAProfileUpdateWithoutFollowersQueuesNothing(): void
	{
		$app = $this->app();

		self::assertSame(0, $app->profileUpdates?->publish('alice'));
		self::assertSame(0, $app->queue->pendingCount());
	}

	private function app(): Application
	{
		$http = new FakeHttpClient();
		$profiles = new InMemoryLocalActorProfileProvider([
			'alice' => new LocalActorProfile(
				'alice',
				'Alice Updated',
				'A changed bio',
				'https://social.example/alice.png',
				null,
				[],
				[],
				false,
				false,
				Time::parse('2026-11-14T09:30:00Z'),
			),
		]);

		$app = Application::inMemory(
			clock: $this->clock,
			httpClient: $http,
			signingKey: self::$key,
			localActors: new InMemoryActorDirectory(['alice']),
			localProfiles: $profiles,
		);
		$this->http = $http;

		return $app;
	}


}
