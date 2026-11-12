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
use AlohaSocial\Core\Platform\Db\PdoConnection;
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
}
