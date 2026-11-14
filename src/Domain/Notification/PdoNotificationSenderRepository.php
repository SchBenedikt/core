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
use PDOException;

final class PdoNotificationSenderRepository implements NotificationSenderRepository
{
	private const COLUMNS = 'id, recipient_id, actor_id, status, category, created_at, reviewed_at, note';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function save(NotificationSender $sender): void
	{
		$params = [
			'id' => $sender->id,
			'recipient_id' => $sender->recipientId,
			'actor_id' => $sender->actorId,
			'status' => $sender->status->value,
			'category' => $sender->category->value,
			'created_at' => Time::format($sender->createdAt),
			'reviewed_at' => $sender->reviewedAt === null ? null : Time::format($sender->reviewedAt),
			'note' => $sender->note,
		];

		try {
			$changed = $this->db->execute(
				'UPDATE ' . NotificationSenderSchema::TABLE
				. ' SET status = :status, category = :category, reviewed_at = :reviewed_at, note = :note'
				. ' WHERE id = :id',
				[
					'status' => $sender->status->value,
					'category' => $sender->category->value,
					'reviewed_at' => $sender->reviewedAt === null ? null : Time::format($sender->reviewedAt),
					'note' => $sender->note,
					'id' => $sender->id,
				],
			);
			if ($changed === 0 && $this->findById($sender->id) === null) {
				$this->db->execute(
					'INSERT INTO ' . NotificationSenderSchema::TABLE
					. ' (' . self::COLUMNS . ')'
					. ' VALUES (:id, :recipient_id, :actor_id, :status, :category, :created_at, :reviewed_at, :note)',
					$params,
				);
			}
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification sender could not be stored.', 0, $exception);
		}
	}

	public function find(string $recipientId, string $actorId): ?NotificationSender
	{
		return $this->findOne('recipient_id = :recipient_id AND actor_id = :actor_id', [
			'recipient_id' => $recipientId,
			'actor_id' => $actorId,
		]);
	}

	public function findById(string $id): ?NotificationSender
	{
		return $this->findOne('id = :id', ['id' => $id]);
	}

	public function forRecipient(
		string $recipientId,
		?NotificationSenderStatus $status = null,
		int $limit = 40,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . NotificationSenderSchema::TABLE
			. ' WHERE recipient_id = :recipient_id';
		$params = ['recipient_id' => $recipientId];
		if ($status !== null) {
			$sql .= ' AND status = :status';
			$params['status'] = $status->value;
		}
		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(200, $limit));

		try {
			return array_map(
				fn (array $row): NotificationSender => $this->hydrate($row),
				$this->db->fetchAll($sql, $params),
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification senders could not be read.', 0, $exception);
		}
	}

	public function remove(string $recipientId, string $actorId): bool
	{
		try {
			return $this->db->execute(
				'DELETE FROM ' . NotificationSenderSchema::TABLE
				. ' WHERE recipient_id = :recipient_id AND actor_id = :actor_id',
				['recipient_id' => $recipientId, 'actor_id' => $actorId],
			) > 0;
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification sender could not be removed.', 0, $exception);
		}
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function findOne(string $where, array $params): ?NotificationSender
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT ' . self::COLUMNS . ' FROM ' . NotificationSenderSchema::TABLE . ' WHERE ' . $where,
				$params,
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The notification sender could not be read.', 0, $exception);
		}

		return $row === null ? null : $this->hydrate($row);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): NotificationSender
	{
		return new NotificationSender(
			id: (string) $row['id'],
			recipientId: (string) $row['recipient_id'],
			actorId: (string) $row['actor_id'],
			status: NotificationSenderStatus::from((string) $row['status']),
			category: NotificationSenderCategory::from((string) $row['category']),
			createdAt: Time::parse((string) $row['created_at']),
			reviewedAt: $row['reviewed_at'] === null ? null : Time::parse((string) $row['reviewed_at']),
			note: $row['note'] === null ? null : (string) $row['note'],
		);
	}
}
