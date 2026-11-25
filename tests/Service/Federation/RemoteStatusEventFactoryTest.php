<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Federation;

use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Service\Federation\RemoteStatusEventFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RemoteStatusEventFactoryTest extends TestCase
{
	private const ACTOR = 'https://peer.example/users/bob';

	private RemoteStatusEventFactory $factory;

	protected function setUp(): void
	{
		$this->factory = new RemoteStatusEventFactory();
	}

	public function testPublicRemotePostProducesAHomeOnlyRecipientEvent(): void
	{
		$event = $this->factory->forCreate(
			$this->post(),
			['object' => ['to' => ['https://www.w3.org/ns/activitystreams#Public']]],
			['alice', 'alice', 'carol'],
		);

		self::assertNotNull($event);
		self::assertSame('status.created', $event->name);
		self::assertSame(StreamAudience::Followers, $event->audience);
		self::assertSame(self::ACTOR, $event->actorId);
		self::assertSame(['alice', 'carol'], $event->recipientIds);
		self::assertSame(['status_id' => 'https://peer.example/posts/1'], $event->payload);
	}

	public function testUnlistedRemotePostIsNotExposedOnPublicStream(): void
	{
		$event = $this->factory->forCreate(
			$this->post(),
			['object' => ['to' => [self::ACTOR . '/followers'], 'cc' => 'https://www.w3.org/ns/activitystreams#Public']],
			['alice'],
		);

		self::assertNotNull($event);
		self::assertSame(StreamAudience::Followers, $event->audience);
	}

	public function testFollowersOnlyRemotePostsAreScopedAndDirectPostsProduceNoEvent(): void
	{
		$activity = ['object' => ['to' => [self::ACTOR . '/followers']]];
		$followersOnly = $this->factory->forCreate($this->post(), $activity, ['alice']);

		self::assertNotNull($followersOnly);
		self::assertSame(StreamAudience::Followers, $followersOnly->audience);

		self::assertNull($this->factory->forCreate(
			$this->post(),
			['object' => ['to' => ['https://server.example/users/alice']]],
			['alice'],
		));
	}

	public function testBoostsAndEventsWithoutLocalFollowersAreNotPublished(): void
	{
		self::assertNull($this->factory->forCreate(
			$this->post(RemotePostKind::Boost),
			['object' => ['to' => ['https://www.w3.org/ns/activitystreams#Public']]],
			['alice'],
		));
		self::assertNull($this->factory->forCreate(
			$this->post(),
			['object' => ['to' => ['https://www.w3.org/ns/activitystreams#Public']]],
			[],
		));
	}

	private function post(RemotePostKind $kind = RemotePostKind::Post): RemotePost
	{
		return new RemotePost(
			activityId: 'https://peer.example/activities/1',
			objectId: 'https://peer.example/posts/1',
			actorId: self::ACTOR,
			kind: $kind,
			content: 'A remote status',
			contentWarning: null,
			publishedAt: new DateTimeImmutable('2026-11-25T00:00:00Z'),
			objectAuthorId: self::ACTOR,
		);
	}
}
