<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both notification repositories must satisfy.
 */
abstract class NotificationRepositoryTestCase extends TestCase
{
	abstract protected function repository(): NotificationRepository;

	public function testInsertFindAndProject(): void
	{
		$repository = $this->repository();
		$notification = $this->notification('n1', 'alice', 'bob', NotificationType::Favourite, 'p1');

		$repository->insert($notification);

		$stored = $repository->find('n1');
		self::assertSame('alice', $stored?->recipientId);
		self::assertSame('bob', $stored?->actorId);
		self::assertSame(NotificationType::Favourite, $stored?->type);
		self::assertSame('p1', $stored?->postId);
		self::assertSame('2026-11-11T10:00:00Z', $stored?->toArray()['created_at']);
		self::assertNull($repository->find('unknown'));
	}

	public function testARepeatedIdIsRefused(): void
	{
		$repository = $this->repository();
		$repository->insert($this->notification('n1', 'alice', 'bob', NotificationType::Follow, null));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->notification('n1', 'alice', 'bob', NotificationType::Follow, null));
	}

	public function testListIsScopedNewestFirstWithIdTieBreak(): void
	{
		$repository = $this->repository();
		$repository->insert($this->notification('aaa', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:00:00Z'));
		$repository->insert($this->notification('cccc', 'alice', 'carol', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));
		$repository->insert($this->notification('bbb', 'alice', 'dave', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));
		$repository->insert($this->notification('other', 'carol', 'bob', NotificationType::Follow, null, '2026-11-11T10:03:00Z'));

		self::assertSame(
			['cccc', 'bbb', 'aaa'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor('alice', 10)),
		);
		self::assertSame([], $repository->listFor('nobody', 10));
	}

	public function testBeforeAndAfterAreExclusiveAndKeepSameSecondIds(): void
	{
		$repository = $this->repository();
		$time = Time::parse('2026-11-11T10:02:00Z');
		$repository->insert($this->notification('aaa', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));
		$repository->insert($this->notification('bbb', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));
		$repository->insert($this->notification('ccc', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:00:00Z'));

		self::assertSame(
			['aaa', 'ccc'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				10,
				$time,
				'bbb',
			)),
		);
		self::assertSame(
			['ccc'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				10,
				$time,
			)),
		);
		self::assertSame(
			['bbb'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				10,
				null,
				null,
				$time,
				'aaa',
			)),
		);
	}

	public function testTypeFiltersAreAppliedBeforeTheLimit(): void
	{
		$repository = $this->repository();
		$repository->insert($this->notification('f1', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:00:00Z'));
		$repository->insert($this->notification('v1', 'alice', 'carol', NotificationType::Favourite, 'p1', '2026-11-11T10:01:00Z'));
		$repository->insert($this->notification('f2', 'alice', 'dave', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));

		self::assertSame(
			['f2', 'f1'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				10,
				null,
				null,
				null,
				null,
				[NotificationType::Follow],
			)),
		);
		self::assertSame(
			['f2', 'f1'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				10,
				null,
				null,
				null,
				null,
				[],
				[NotificationType::Favourite],
			)),
		);
		self::assertSame(
			['f2'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				1,
				null,
				null,
				null,
				null,
				[NotificationType::Follow],
			)),
		);
	}

	public function testReadMarkerIsScopedIdempotentAndHidesByDefault(): void
	{
		$repository = $this->repository();
		$repository->insert($this->notification('n1', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:00:00Z'));
		$repository->insert($this->notification('n2', 'alice', 'carol', NotificationType::Favourite, 'p1', '2026-11-11T10:01:00Z'));
		$repository->insert($this->notification('n3', 'bob', 'bob', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));

		$readAt = Time::parse('2026-11-11T10:03:00Z');
		self::assertTrue($repository->markRead('n1', 'alice', $readAt));
		self::assertTrue($repository->find('n1')?->isRead());
		self::assertSame('2026-11-11T10:03:00Z', $repository->find('n1')?->toArray()['read_at']);

		// A repeat keeps the first marker and still reports success.
		self::assertTrue($repository->markRead('n1', 'alice', Time::parse('2026-11-11T10:04:00Z')));
		self::assertSame('2026-11-11T10:03:00Z', $repository->find('n1')?->toArray()['read_at']);

		self::assertFalse($repository->markRead('n2', 'bob', $readAt));
		self::assertFalse($repository->markRead('missing', 'alice', $readAt));
		self::assertFalse($repository->find('n2')?->isRead());

		self::assertSame(
			['n2'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor('alice', 10)),
		);
		self::assertSame(
			['n2', 'n1'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor(
				'alice',
				10,
				null,
				null,
				null,
				null,
				[],
				[],
				true,
			)),
		);
		self::assertSame(
			['n3'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor('bob', 10)),
		);
	}

	public function testMarkAllReadIsScopedAndNewRowsStayUnread(): void
	{
		$repository = $this->repository();
		$repository->insert($this->notification('n1', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:00:00Z'));
		$repository->insert($this->notification('n2', 'alice', 'carol', NotificationType::Favourite, 'p1', '2026-11-11T10:01:00Z'));
		$repository->insert($this->notification('n3', 'bob', 'bob', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));

		self::assertSame(2, $repository->countUnread('alice'));
		self::assertSame(1, $repository->countUnread('bob'));
		self::assertSame(2, $repository->markAllRead('alice', Time::parse('2026-11-11T10:03:00Z')));
		self::assertSame(0, $repository->markAllRead('alice', Time::parse('2026-11-11T10:04:00Z')));
		self::assertSame(0, $repository->countUnread('alice'));
		self::assertSame(1, $repository->countUnread('bob'));
		self::assertSame([], $repository->listFor('alice', 10));

		$repository->insert($this->notification('n4', 'alice', 'dave', NotificationType::Follow, null, '2026-11-11T10:05:00Z'));

		self::assertSame(1, $repository->countUnread('alice'));
		self::assertSame(
			['n4'],
			array_map(static fn (Notification $notification): string => $notification->id, $repository->listFor('alice', 10)),
		);
		self::assertCount(3, $repository->listFor(
			'alice',
			10,
			null,
			null,
			null,
			null,
			[],
			[],
			true,
		));
	}

	public function testUnreadCountSupportsActorTypeAndExcludeFilters(): void
	{
		$repository = $this->repository();
		$repository->insert($this->notification('f1', 'alice', 'bob', NotificationType::Follow, null, '2026-11-11T10:00:00Z'));
		$repository->insert($this->notification('v1', 'alice', 'bob', NotificationType::Favourite, 'p1', '2026-11-11T10:01:00Z'));
		$repository->insert($this->notification('f2', 'alice', 'carol', NotificationType::Follow, null, '2026-11-11T10:02:00Z'));

		self::assertSame(3, $repository->countUnread('alice'));
		self::assertSame(2, $repository->countUnread('alice', 'bob'));
		self::assertSame(1, $repository->countUnread('alice', 'carol'));
		self::assertSame(2, $repository->countUnread('alice', null, [NotificationType::Follow]));
		self::assertSame(1, $repository->countUnread('alice', null, [], [NotificationType::Follow]));
		self::assertSame(1, $repository->countUnread('alice', 'carol', [NotificationType::Follow]));

		self::assertTrue($repository->markRead('v1', 'alice', Time::parse('2026-11-11T10:03:00Z')));

		self::assertSame(2, $repository->countUnread('alice'));
		self::assertSame(1, $repository->countUnread('alice', 'bob'));
		self::assertSame(0, $repository->countUnread('alice', null, [NotificationType::Favourite]));
	}

	private function notification(
		string $id,
		string $recipientId,
		string $actorId,
		NotificationType $type,
		?string $postId,
		string $createdAt = '2026-11-11T10:00:00Z',
	): Notification {
		return new Notification(
			$id,
			$recipientId,
			$actorId,
			$type,
			$postId,
			Time::parse($createdAt),
		);
	}
}
