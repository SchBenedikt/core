<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

/**
 * Held sender requests and explicit allow/dismiss decisions.
 *
 * The row identity is (recipient, actor), so a request cannot be duplicated
 * and an accept or dismiss is a transition of the same audit row.
 */
interface NotificationSenderRepository
{
	public function save(NotificationSender $sender): void;

	public function find(string $recipientId, string $actorId): ?NotificationSender;

	public function findById(string $id): ?NotificationSender;

	/**
	 * Sender requests of one recipient, newest first.
	 *
	 * @return list<NotificationSender>
	 */
	public function forRecipient(
		string $recipientId,
		?NotificationSenderStatus $status = null,
		int $limit = 40,
	): array;

	public function remove(string $recipientId, string $actorId): bool;
}
