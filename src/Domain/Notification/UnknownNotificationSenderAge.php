<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

final readonly class UnknownNotificationSenderAge implements NotificationSenderAge
{
	public function ageInDays(string $actorId): ?int
	{
		return null;
	}
}
