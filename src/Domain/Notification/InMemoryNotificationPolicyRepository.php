<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use DateTimeImmutable;

use function trim;

final class InMemoryNotificationPolicyRepository implements NotificationPolicyRepository
{
	/**
	 * @var array<string, NotificationPolicy>
	 */
	private array $policies = [];

	public function find(string $userId): ?NotificationPolicy
	{
		return $this->policies[trim($userId)] ?? null;
	}

	public function save(string $userId, NotificationPolicy $policy, DateTimeImmutable $updatedAt): void
	{
		$this->policies[trim($userId)] = $policy;
	}
}
