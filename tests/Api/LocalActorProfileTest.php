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
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class LocalActorProfileTest extends TestCase
{
	private const ACTOR = 'https://localhost:8080/@alice';

	private static RsaKeyPair $key;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
	}

	public function testTheActorDocumentCarriesTheHostProfile(): void
	{
		$response = $this->app()->handle(new Request('GET', '/@alice'));

		self::assertSame(200, $response->status);
		$document = $response->decodedBody();
		self::assertSame('Alice Example', $document['name']);
		self::assertSame('A public bio', $document['summary']);
		self::assertSame('https://social.example/alice.png', $document['icon']['url']);
		self::assertSame('https://social.example/header.png', $document['image']['url']);
		self::assertTrue($document['manuallyApprovesFollowers']);
		self::assertSame('2026-11-14T09:00:00Z', $document['updated']);
		self::assertSame([[
			'type' => 'PropertyValue',
			'name' => 'Website',
			'value' => 'https://alice.example',
			'rel' => ['me'],
		]], [$document['attachment'][1]]);
		self::assertSame(['type' => 'Image', 'url' => 'https://social.example/one.png'], $document['attachment'][0]);
	}

	public function testHiddenCollectionsKeepCountsButHideAnonymousMembers(): void
	{
		$app = $this->app();
		$app->followers->follow(new FollowActor(
			'alice',
			'https://a.example/users/one',
			'https://a.example/users/one/inbox',
		));

		$root = $app->handle(new Request('GET', '/@alice/followers'))->decodedBody();
		self::assertSame(1, $root['totalItems']);

		$anonymous = $app->handle(new Request('GET', '/@alice/followers', ['page' => '1']))->decodedBody();
		self::assertSame([], $anonymous['orderedItems']);
		self::assertSame(1, $anonymous['totalItems']);

		$owner = $app->handle(new Request('GET', '/@alice/followers', ['page' => '1'], userId: 'alice'))->decodedBody();
		self::assertSame(['https://a.example/users/one'], $owner['orderedItems']);
	}

	private function app(): Application
	{
		$profiles = new InMemoryLocalActorProfileProvider([
			'alice' => new LocalActorProfile(
				userId: 'alice',
				displayName: 'Alice Example',
				summary: 'A public bio',
				avatarUrl: 'https://social.example/alice.png',
				headerUrl: 'https://social.example/header.png',
				attachmentUrls: ['https://social.example/one.png'],
				fields: [new LocalActorProfileField('Website', 'https://alice.example', true)],
				locked: true,
				hideCollections: true,
				updatedAt: Time::parse('2026-11-14T09:00:00Z'),
			),
		]);

		return Application::inMemory(
			clock: $this->clock,
			signingKey: self::$key,
			localActors: new InMemoryActorDirectory(['alice']),
			localProfiles: $profiles,
		);
	}
}
