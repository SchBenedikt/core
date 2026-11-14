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

final class PdoConversationMuteRepository implements ConversationMuteRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function save(ConversationMute $mute): void
	{
		try {
			$changed = $this->db->execute(
				'UPDATE ' . ConversationMuteSchema::TABLE . ' SET created_at = :created_at WHERE id = :id',
				['created_at' => Time::format($mute->createdAt), 'id' => $mute->id],
			);
			if ($changed === 0 && $this->find($mute->userId, $mute->conversationId) === null) {
				$this->db->execute(
					'INSERT INTO ' . ConversationMuteSchema::TABLE
					. ' (id, user_id, conversation_id, created_at)'
					. ' VALUES (:id, :user_id, :conversation_id, :created_at)',
					[
						'id' => $mute->id,
						'user_id' => $mute->userId,
						'conversation_id' => $mute->conversationId,
						'created_at' => Time::format($mute->createdAt),
					],
				);
			}
		} catch (PDOException $exception) {
			throw new DatabaseException('The conversation mute could not be stored.', 0, $exception);
		}
	}

	public function find(string $userId, string $conversationId): ?ConversationMute
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT id, user_id, conversation_id, created_at FROM ' . ConversationMuteSchema::TABLE
				. ' WHERE id = :id',
				['id' => ConversationMute::idFor($userId, $conversationId)],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The conversation mute could not be read.', 0, $exception);
		}

		return $row === null ? null : new ConversationMute(
			id: (string) $row['id'],
			userId: (string) $row['user_id'],
			conversationId: (string) $row['conversation_id'],
			createdAt: Time::parse((string) $row['created_at']),
		);
	}

	public function remove(string $userId, string $conversationId): bool
	{
		try {
			return $this->db->execute(
				'DELETE FROM ' . ConversationMuteSchema::TABLE . ' WHERE id = :id',
				['id' => ConversationMute::idFor($userId, $conversationId)],
			) > 0;
		} catch (PDOException $exception) {
			throw new DatabaseException('The conversation mute could not be removed.', 0, $exception);
		}
	}
}
