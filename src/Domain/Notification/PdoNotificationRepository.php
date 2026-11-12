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
 * database.
 */
final class PdoNotificationRepository implements NotificationRepository
{
	private const COLUMNS = 'id, recipient_id, actor_id, type, post_id, created_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Notification $notification): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . NotificationSchema::TABLE
				. ' (id, recipient_id, actor_id, type, post_id, created_at)'
				. ' VALUES (:id, :recipient_id, :actor_id, :type, :post_id, :created_at)',
				[
					'id' => $notification->id,
					'recipient_id' => $notification->recipientId,
					'actor_id' => $notification->actorId,
					'type' => $notification->type->value,
					'post_id' => $notification->postId,
					'created_at' => Time::format($notification->createdAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification could not be stored.', 0, $exception);
		}
	}

	public function find(string $id): ?Notification
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . NotificationSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

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
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . NotificationSchema::TABLE
			. ' WHERE recipient_id = :recipient_id';
		$parameters = ['recipient_id' => $recipientId];

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

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): Notification => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
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
		);
	}
}
