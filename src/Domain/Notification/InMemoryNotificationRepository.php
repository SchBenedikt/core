<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_filter;
use function array_slice;
use function array_values;
use function in_array;
use function usort;

/**
 * Notification storage in memory, for tests, the demo and tools.
 *
 * The same filters, cursor rules and id tie-break as the SQL adapter are
 * applied here so the two cannot drift unnoticed.
 */
final class InMemoryNotificationRepository implements NotificationRepository
{
	/**
	 * @var array<string, Notification>
	 */
	private array $notifications = [];

	public function insert(Notification $notification): void
	{
		if (isset($this->notifications[$notification->id])) {
			throw new DatabaseException('The notification is already stored.');
		}

		$this->notifications[$notification->id] = $notification;
	}

	public function find(string $id): ?Notification
	{
		return $this->notifications[$id] ?? null;
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
		$notifications = array_values(array_filter(
			$this->notifications,
			static function (Notification $notification) use (
				$recipientId,
				$before,
				$beforeId,
				$after,
				$afterId,
				$types,
				$excludeTypes,
				$includeRead,
				$excludeActorIds,
			): bool {
				if ($notification->recipientId !== $recipientId) {
					return false;
				}

				if (in_array($notification->actorId, $excludeActorIds, true)) {
					return false;
				}

				if (!$includeRead && $notification->isRead()) {
					return false;
				}

				if ($types !== [] && !in_array($notification->type, $types, true)) {
					return false;
				}

				if (in_array($notification->type, $excludeTypes, true)) {
					return false;
				}

				if ($before !== null) {
					if ($notification->createdAt > $before) {
						return false;
					}

					if ($notification->createdAt == $before
						&& ($beforeId === null || strcmp($notification->id, $beforeId) >= 0)
					) {
						return false;
					}
				}

				if ($after !== null) {
					if ($notification->createdAt < $after) {
						return false;
					}

					if ($notification->createdAt == $after
						&& ($afterId === null || strcmp($notification->id, $afterId) <= 0)
					) {
						return false;
					}
				}

				return true;
			},
		));

		usort($notifications, static function (Notification $a, Notification $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($notifications, 0, max(1, $limit));
	}

	public function markRead(string $id, string $recipientId, DateTimeImmutable $readAt): bool
	{
		$notification = $this->notifications[$id] ?? null;
		if ($notification === null || $notification->recipientId !== $recipientId) {
			return false;
		}

		if (!$notification->isRead()) {
			$this->notifications[$id] = $notification->withReadAt(Time::toSecondPrecision($readAt));
		}

		return true;
	}

	public function markAllRead(string $recipientId, DateTimeImmutable $readAt): int
	{
		$marked = 0;
		$readAt = Time::toSecondPrecision($readAt);
		foreach ($this->notifications as $id => $notification) {
			if ($notification->recipientId !== $recipientId || $notification->isRead()) {
				continue;
			}

			$this->notifications[$id] = $notification->withReadAt($readAt);
			$marked++;
		}

		return $marked;
	}

	public function countUnread(
		string $recipientId,
		?string $accountId = null,
		array $types = [],
		array $excludeTypes = [],
		array $excludeActorIds = [],
	): int {
		$count = 0;
		foreach ($this->notifications as $notification) {
			if ($notification->recipientId !== $recipientId || $notification->isRead()) {
				continue;
			}

			if (in_array($notification->actorId, $excludeActorIds, true)) {
				continue;
			}

			if ($accountId !== null && $notification->actorId !== $accountId) {
				continue;
			}

			if ($types !== [] && !in_array($notification->type, $types, true)) {
				continue;
			}

			if (in_array($notification->type, $excludeTypes, true)) {
				continue;
			}

			$count++;
		}

		return $count;
	}
}
