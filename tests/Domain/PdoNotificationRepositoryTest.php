<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Notification\PdoNotificationRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use DateTimeImmutable;
use PDO;

final class PdoNotificationRepositoryTest extends NotificationRepositoryTestCase
{
	private NotificationRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		NotificationSchema::create($connection);

		$this->repository = new PdoNotificationRepository($connection);
	}

	protected function repository(): NotificationRepository
	{
		return $this->repository;
	}

	public function testAListingOnlySchemaFailsReadMarkersWithoutDeletingRows(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		$connection->execute(
			'CREATE TABLE social_notification ('
			. ' id VARCHAR(64) NOT NULL PRIMARY KEY,'
			. ' recipient_id VARCHAR(255) NOT NULL,'
			. ' actor_id VARCHAR(255) NOT NULL,'
			. ' type VARCHAR(32) NOT NULL,'
			. ' post_id VARCHAR(64) NULL,'
			. ' created_at VARCHAR(20) NOT NULL'
			. ')',
		);
		$connection->execute(
			"INSERT INTO social_notification (id, recipient_id, actor_id, type, post_id, created_at)"
			. " VALUES ('n1', 'alice', 'bob', 'follow', NULL, '2026-11-11T10:00:00Z')",
		);
		$repository = new PdoNotificationRepository($connection);

		try {
			$repository->markRead('n1', 'alice', new DateTimeImmutable('2026-11-11T10:01:00Z'));
			self::fail('An old table without read_at must not be silently accepted.');
		} catch (DatabaseException) {
			// The host must apply the read-marker migration.
		}

		self::assertSame(1, (int) $connection->fetchValue('SELECT COUNT(*) FROM social_notification'));
	}
}
