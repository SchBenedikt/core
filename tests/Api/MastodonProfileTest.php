<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryLocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileField;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class MastodonProfileTest extends TestCase
{
	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
	}

	public function testTheAccountShapeCarriesTheHostProfileFields(): void
	{
		$response = $this->app()->handle($this->request('/accounts/alice', 'alice'));

		self::assertSame(200, $response->status);
		$account = $response->decodedBody();
		self::assertSame('Alice Example', $account['display_name']);
		self::assertSame('A public bio', strip_tags($account['note']));
		self::assertSame('https://social.example/alice.png', $account['avatar']);
		self::assertSame('https://social.example/header.png', $account['header']);
		self::assertTrue($account['locked']);
		self::assertSame('Website', $account['fields'][0]['name']);
		self::assertStringContainsString('https://alice.example', $account['fields'][0]['value']);
		self::assertSame('2026-11-14T09:00:00.000Z', $account['fields'][0]['verified_at']);
	}

	public function testHiddenCollectionsAreEmptyForOtherViewersButNotTheOwner(): void
	{
		$app = $this->app();
		$app->followers->follow(
			new FollowActor('alice', 'https://localhost:8080/@bob', 'https://localhost:8080/inbox/shared'),
			local: true,
		);

		$owner = $app->handle($this->request('/accounts/alice/followers', 'alice'));
		self::assertSame(200, $owner->status);
		self::assertCount(1, $owner->decodedBody());

		$other = $app->handle($this->request('/accounts/alice/followers', 'bob'));
		self::assertSame(200, $other->status);
		self::assertSame([], $other->decodedBody());

		$otherFollowing = $app->handle($this->request('/accounts/alice/following', 'bob'));
		self::assertSame(200, $otherFollowing->status);
		self::assertSame([], $otherFollowing->decodedBody());
	}

	private function app(): Application
	{
		$profiles = new InMemoryLocalActorProfileProvider([
			'alice' => new LocalActorProfile(
				'alice',
				'Alice Example',
				'A public bio',
				'https://social.example/alice.png',
				'https://social.example/header.png',
				[],
				[new LocalActorProfileField('Website', 'https://alice.example', true)],
				true,
				true,
				Time::parse('2026-11-14T09:00:00Z'),
			),
		]);

		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'mastodon.api' => 'true',
			]]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			localProfiles: $profiles,
		);
	}

	private function request(string $path, string $viewer): Request
	{
		return new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . $path,
			userId: $viewer,
			scopes: ['read:accounts'],
		);
	}
}
