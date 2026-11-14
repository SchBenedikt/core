<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use DateTimeImmutable;

/**
 * Per-recipient notification policy overrides.
 *
 * A missing row means the host has not chosen a policy for this account; the
 * policy service then applies {@see NotificationPolicy::default()}. Storing a
 * policy never rewrites historical notifications.
 */
interface NotificationPolicyRepository
{
	public function find(string $userId): ?NotificationPolicy;

	public function save(string $userId, NotificationPolicy $policy, DateTimeImmutable $updatedAt): void;
}
