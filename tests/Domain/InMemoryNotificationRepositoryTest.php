<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;

final class InMemoryNotificationRepositoryTest extends NotificationRepositoryTestCase
{
	private NotificationRepository $repository;

	protected function setUp(): void
	{
		$this->repository = new InMemoryNotificationRepository();
	}

	protected function repository(): NotificationRepository
	{
		return $this->repository;
	}
}
