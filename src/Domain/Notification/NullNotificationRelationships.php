<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

final readonly class NullNotificationRelationships implements NotificationRelationshipReader
{
	public function follows(string $recipientId, string $actorId): bool
	{
		return false;
	}

	public function isFollowedBy(string $recipientId, string $actorId): bool
	{
		return false;
	}
}
