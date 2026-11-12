<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use DateTimeImmutable;

/**
 * Storage of local account notifications.
 *
 * The id is deterministic from the event, so a repeated favourite or follow
 * cannot create a second notification. Rows are independent of the post and
 * follower they came from; deleting a post does not erase the historical
 * notification.
 */
interface NotificationRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is already stored
	 */
	public function insert(Notification $notification): void;

	public function find(string $id): ?Notification;

	/**
	 * One page for a recipient, newest first with the id as tie-break.
	 *
	 * @param list<NotificationType> $types        only these types when non-empty
	 * @param list<NotificationType> $excludeTypes omit these types
	 *
	 * @return list<Notification>
	 */
	public function listFor(
		string $recipientId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
		array $types = [],
		array $excludeTypes = [],
	): array;
}
