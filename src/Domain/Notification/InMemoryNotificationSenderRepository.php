<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use function array_filter;
use function array_slice;
use function array_values;
use function strcmp;
use function usort;

final class InMemoryNotificationSenderRepository implements NotificationSenderRepository
{
	/**
	 * @var array<string, NotificationSender>
	 */
	private array $senders = [];

	public function save(NotificationSender $sender): void
	{
		$this->senders[$sender->id] = $sender;
	}

	public function find(string $recipientId, string $actorId): ?NotificationSender
	{
		$id = NotificationSender::idFor($recipientId, $actorId);

		return $this->senders[$id] ?? null;
	}

	public function findById(string $id): ?NotificationSender
	{
		return $this->senders[$id] ?? null;
	}

	public function forRecipient(
		string $recipientId,
		?NotificationSenderStatus $status = null,
		int $limit = 40,
	): array {
		$rows = array_values(array_filter(
			$this->senders,
			static fn (NotificationSender $sender): bool => $sender->recipientId === $recipientId
				&& ($status === null || $sender->status === $status),
		));

		usort($rows, static function (NotificationSender $a, NotificationSender $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($rows, 0, max(1, $limit));
	}

	public function remove(string $recipientId, string $actorId): bool
	{
		$id = NotificationSender::idFor($recipientId, $actorId);
		if (!isset($this->senders[$id])) {
			return false;
		}

		unset($this->senders[$id]);

		return true;
	}
}
