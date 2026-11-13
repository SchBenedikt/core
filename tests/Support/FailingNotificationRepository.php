<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

/**
 * Simulates a missing or unreadable notification table.
 *
 * Notification recording must stay best-effort, while the list route reports
 * a stable 503 instead of silently returning an empty page.
 */
final class FailingNotificationRepository implements NotificationRepository
{
	public function insert(Notification $notification): void
	{
		throw new DatabaseException('The notification table is unavailable.');
	}

	public function find(string $id): ?Notification
	{
		throw new DatabaseException('The notification table is unavailable.');
	}

	public function listFor(
		string $recipientId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
		array $types = [],
		array $excludeTypes = [],
		bool $includeRead = false,
		array $excludeActorIds = [],
	): array {
		throw new DatabaseException('The notification table is unavailable.');
	}

	public function markRead(string $id, string $recipientId, DateTimeImmutable $readAt): bool
	{
		throw new DatabaseException('The notification table is unavailable.');
	}

	public function markAllRead(string $recipientId, DateTimeImmutable $readAt): int
	{
		throw new DatabaseException('The notification table is unavailable.');
	}

	public function countUnread(
		string $recipientId,
		?string $accountId = null,
		array $types = [],
		array $excludeTypes = [],
		array $excludeActorIds = [],
	): int {
		throw new DatabaseException('The notification table is unavailable.');
	}
}
