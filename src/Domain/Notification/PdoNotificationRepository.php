<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PDOException;

use function array_values;
use function implode;

/**
 * Notification storage in SQL.
 *
 * The primary key is the deterministic event id. Timestamps use the canonical
 * UTC form, so the cursor and ordering behave identically on every supported
 * database. A nullable `read_at` is the durable read marker; dismiss and clear
 * set it without deleting the historical event.
 */
final class PdoNotificationRepository implements NotificationRepository
{
	private const COLUMNS = 'id, recipient_id, actor_id, type, post_id, created_at, read_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Notification $notification): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . NotificationSchema::TABLE
				. ' (id, recipient_id, actor_id, type, post_id, created_at, read_at)'
				. ' VALUES (:id, :recipient_id, :actor_id, :type, :post_id, :created_at, :read_at)',
				[
					'id' => $notification->id,
					'recipient_id' => $notification->recipientId,
					'actor_id' => $notification->actorId,
					'type' => $notification->type->value,
					'post_id' => $notification->postId,
					'created_at' => Time::format($notification->createdAt),
					'read_at' => $notification->readAt === null ? null : Time::format($notification->readAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification could not be stored.', 0, $exception);
		}
	}

	public function find(string $id): ?Notification
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT ' . self::COLUMNS . ' FROM ' . NotificationSchema::TABLE . ' WHERE id = :id',
				['id' => $id],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification could not be read.', 0, $exception);
		}

		return $row === null ? null : $this->hydrate($row);
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
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . NotificationSchema::TABLE
			. ' WHERE recipient_id = :recipient_id';
		$parameters = ['recipient_id' => $recipientId];

		$this->appendFilters($sql, $parameters, $types, $excludeTypes, null, !$includeRead);

		if ($before !== null) {
			if ($beforeId === null) {
				$sql .= ' AND created_at < :before';
			} else {
				$sql .= ' AND (created_at < :before OR (created_at = :before AND id < :before_id))';
				$parameters['before_id'] = $beforeId;
			}
			$parameters['before'] = Time::format($before);
		}

		if ($after !== null) {
			if ($afterId === null) {
				$sql .= ' AND created_at > :after';
			} else {
				$sql .= ' AND (created_at > :after OR (created_at = :after AND id > :after_id))';
				$parameters['after_id'] = $afterId;
			}
			$parameters['after'] = Time::format($after);
		}

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

		try {
			$rows = $this->db->fetchAll($sql, $parameters);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notifications could not be read.', 0, $exception);
		}

		return array_map(
			fn (array $row): Notification => $this->hydrate($row),
			$rows,
		);
	}

	public function markRead(string $id, string $recipientId, DateTimeImmutable $readAt): bool
	{
		try {
			$changed = $this->db->execute(
				'UPDATE ' . NotificationSchema::TABLE
				. ' SET read_at = :read_at'
				. ' WHERE id = :id AND recipient_id = :recipient_id AND read_at IS NULL',
				[
					'read_at' => Time::format($readAt),
					'id' => $id,
					'recipient_id' => $recipientId,
				],
			);

			if ($changed > 0) {
				return true;
			}

			// A repeat dismiss finds the row already read. Only the owner may
			// turn that into success; a foreign or missing id stays invisible.
			$notification = $this->find($id);

			return $notification !== null && $notification->recipientId === $recipientId;
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification could not be marked read.', 0, $exception);
		}
	}

	public function markAllRead(string $recipientId, DateTimeImmutable $readAt): int
	{
		try {
			return $this->db->execute(
				'UPDATE ' . NotificationSchema::TABLE
				. ' SET read_at = :read_at'
				. ' WHERE recipient_id = :recipient_id AND read_at IS NULL',
				[
					'read_at' => Time::format($readAt),
					'recipient_id' => $recipientId,
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notifications could not be marked read.', 0, $exception);
		}
	}

	public function countUnread(
		string $recipientId,
		?string $accountId = null,
		array $types = [],
		array $excludeTypes = [],
	): int {
		$sql = 'SELECT COUNT(*) FROM ' . NotificationSchema::TABLE
			. ' WHERE recipient_id = :recipient_id';
		$parameters = ['recipient_id' => $recipientId];

		$this->appendFilters($sql, $parameters, $types, $excludeTypes, $accountId, true);

		try {
			return (int) $this->db->fetchValue($sql, $parameters);
		} catch (PDOException $exception) {
			throw new DatabaseException('Unread notifications could not be counted.', 0, $exception);
		}
	}

	/**
	 * Builds the shared read/actor/type filter for list and count queries.
	 *
	 * @param array<string, mixed>   $parameters
	 * @param list<NotificationType> $types
	 * @param list<NotificationType> $excludeTypes
	 */
	private function appendFilters(
		string &$sql,
		array &$parameters,
		array $types,
		array $excludeTypes,
		?string $accountId,
		bool $unreadOnly,
	): void {
		if ($unreadOnly) {
			$sql .= ' AND read_at IS NULL';
		}

		if ($accountId !== null) {
			$sql .= ' AND actor_id = :account_id';
			$parameters['account_id'] = $accountId;
		}

		if ($types !== []) {
			$placeholders = [];
			foreach (array_values($types) as $index => $type) {
				$name = 'type_' . $index;
				$placeholders[] = ':' . $name;
				$parameters[$name] = $type->value;
			}
			$sql .= ' AND type IN (' . implode(', ', $placeholders) . ')';
		}

		if ($excludeTypes !== []) {
			$placeholders = [];
			foreach (array_values($excludeTypes) as $index => $type) {
				$name = 'exclude_type_' . $index;
				$placeholders[] = ':' . $name;
				$parameters[$name] = $type->value;
			}
			$sql .= ' AND type NOT IN (' . implode(', ', $placeholders) . ')';
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Notification
	{
		return new Notification(
			id: (string) $row['id'],
			recipientId: (string) $row['recipient_id'],
			actorId: (string) $row['actor_id'],
			type: NotificationType::from((string) $row['type']),
			postId: $row['post_id'] === null ? null : (string) $row['post_id'],
			createdAt: Time::parse((string) $row['created_at']),
			readAt: $row['read_at'] === null ? null : Time::parse((string) $row['read_at']),
		);
	}
}
