<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Notification\PdoNotificationRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Platform\Events\EventBroker;
use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\ArrayPsrLogger;
use AlohaSocial\Core\Tests\Support\FailingNotificationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testRepeatingAnEventKeepsOneRowAndOneUnreadCount(): void
	{
		$repository = new InMemoryNotificationRepository();
		$service = new NotificationService($repository, $this->clock);

		$service->recordFavourite('alice', 'bob', 'post-1');
		$service->recordFavourite('alice', 'bob', 'post-1');

		self::assertSame(1, $repository->countUnread('alice'));
		self::assertCount(1, $service->listFor('alice', 10));
	}

	public function testTheSqlPrimaryKeyMakesARetryIdempotent(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		NotificationSchema::create($connection);
		$repository = new PdoNotificationRepository($connection);
		$service = new NotificationService($repository, $this->clock);

		$service->recordFollow('alice', 'bob');
		$service->recordFollow('alice', 'bob');

		self::assertSame(1, $repository->countUnread('alice'));
		self::assertCount(1, $service->listFor('alice', 10));
	}

	public function testVisibleNotificationsPublishARecipientScopedStreamEvent(): void
	{
		$broker = $this->createMock(EventBroker::class);
		$broker->expects(self::once())
			->method('publish')
			->with(self::callback(static fn (StreamEventDraft $event): bool =>
				$event->name === 'notification.created'
				&& $event->audience === StreamAudience::Direct
				&& $event->actorId === 'alice'
				&& $event->recipientIds === ['alice']
				&& $event->payload['notification_id'] === 'notification-'
					. substr(hash('sha256', "alice\0bob\0follow\0\0"), 0, 32)
			));

		$service = new NotificationService(new InMemoryNotificationRepository(), $this->clock, events: $broker);
		$service->recordFollow('alice', 'bob');
	}

	public function testAStorageFailureIsLoggedWithoutFailingThePrimaryWrite(): void
	{
		$logger = new ArrayPsrLogger();
		$service = new NotificationService(new FailingNotificationRepository(), $this->clock, $logger);

		$service->recordFavourite('alice', 'bob', 'post-1');

		self::assertSame(['error'], $logger->levels());
		self::assertStringContainsString('could not be recorded', $logger->records[0]['message']);
	}
}
