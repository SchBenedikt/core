<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\FollowPolicy;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
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

/**
 * A verified Follow is not automatically an accepted Follow.
 *
 * The policy runs before the follower edge is written. A refusal therefore
 * leaves no graph change and releases the activity claim, so the peer can retry
 * the same signed activity after the host changes its mind.
 */
final class FollowPolicyTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private const LOCAL_ACTOR = 'https://localhost:8080/@alice';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	public static function setUpBeforeClass(): void
	{
		self::$remoteKey = RsaKeyPair::generate();
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->http = new FakeHttpClient();
	}

	public function testAPolicyCanRefuseAndTheSameActivityCanBeRetriedAfterAcceptance(): void
	{
		$policy = new class implements FollowPolicy {
			public bool $accept = false;

			public function accepts(RemoteActor $actor, string $localUserId): bool
			{
				return $this->accept;
			}
		};
		$app = $this->app($policy);
		$activity = $this->follow();

		$refused = $this->deliver($app, $activity);

		self::assertSame(403, $refused->status);
		self::assertSame('follow_not_accepted', $refused->decodedBody()['error']['code']);
		self::assertSame(0, $app->followers->count('alice'));
		self::assertSame(0, $app->queue->pendingCount());

		$policy->accept = true;
		$accepted = $this->deliver($app, $activity);

		self::assertSame(202, $accepted->status);
		self::assertSame(1, $app->followers->count('alice'));
		self::assertSame(1, $app->queue->pendingCount());
	}

	private function app(FollowPolicy $policy): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::KEY_ID => new RemoteActor(
					self::ACTOR,
					self::KEY_ID,
					self::INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			followPolicy: $policy,
		);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(Application $app, array $activity): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::KEY_ID,
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
	private function follow(): array
	{
		return [
			'id' => 'https://remote.example/activities/follow-policy-1',
			'type' => 'Follow',
			'actor' => self::ACTOR,
			'object' => self::LOCAL_ACTOR,
		];
	}
}
