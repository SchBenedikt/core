<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\DatabaseException;
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
			): bool {
				if ($notification->recipientId !== $recipientId) {
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
}
