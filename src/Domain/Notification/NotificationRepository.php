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
	 * Read notifications are omitted by default because Mastodon's dismiss
	 * removes them from the client view. The read rows stay stored so a repeat
	 * dismiss can remain idempotent and the host can prune them later.
	 *
	 * @param list<NotificationType> $types           only these types when non-empty
	 * @param list<NotificationType> $excludeTypes    omit these types
	 * @param list<string>           $excludeActorIds omit events caused by these actors
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
		bool $includeRead = false,
		array $excludeActorIds = [],
	): array;

	/**
	 * Marks one notification of this recipient as read.
	 *
	 * Returns false for a missing or foreign row. An already-read row belonging
	 * to the recipient returns true, so the HTTP dismiss is idempotent.
	 */
	public function markRead(string $id, string $recipientId, DateTimeImmutable $readAt): bool;

	/**
	 * Marks every unread notification of one recipient as read.
	 *
	 * @return int the number of rows changed
	 */
	public function markAllRead(string $recipientId, DateTimeImmutable $readAt): int;

	/**
	 * Read notifications whose read marker is older than the boundary. Unread
	 * rows are never candidates, regardless of their age.
	 */
	public function countReadBefore(DateTimeImmutable $before): int;

	/**
	 * Deletes at most `$limit` read notifications older than the boundary,
	 * oldest read marker first with the id as the tie-break.
	 *
	 * @return int number of removed rows
	 */
	public function deleteReadBefore(DateTimeImmutable $before, int $limit): int;

	/**
	 * Counts unread notifications for a recipient, with optional local filters.
	 *
	 * The service caps the returned number; the repository returns the uncapped
	 * count so a caller can decide on its own limit without loading rows.
	 *
	 * @param list<NotificationType> $types           only these types when non-empty
	 * @param list<NotificationType> $excludeTypes    omit these types
	 * @param list<string>           $excludeActorIds omit events caused by these actors
	 */
	public function countUnread(
		string $recipientId,
		?string $accountId = null,
		array $types = [],
		array $excludeTypes = [],
		array $excludeActorIds = [],
	): int;

	/**
	 * Moves this recipient's stored events from one sender out of a policy
	 * state, for example held to visible when a sender request is accepted.
	 *
	 * @return int number of rows changed
	 */
	public function markStatusForActor(
		string $recipientId,
		string $actorId,
		NotificationRecordStatus $from,
		NotificationRecordStatus $to,
	): int;
}
