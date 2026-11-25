<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Conversation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PDOException;

/** SQL persistence for local conversation markers. */
final class PdoConversationMarkerRepository implements ConversationMarkerRepository
{
	public function __construct(private readonly Connection $db)
	{
	}

	public function find(string $userId, string $conversationId): ?ConversationMarker
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT id, user_id, conversation_id, read_through_post_id, read_through_at,'
				. ' dismissed_through_post_id, dismissed_through_at FROM ' . ConversationMarkerSchema::TABLE
				. ' WHERE id = :id',
				['id' => ConversationMarker::idFor($userId, $conversationId)],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('Conversation marker could not be read.', 0, $exception);
		}

		return $row === null ? null : new ConversationMarker(
			id: (string) $row['id'],
			userId: (string) $row['user_id'],
			conversationId: (string) $row['conversation_id'],
			readThroughPostId: $row['read_through_post_id'] === null ? null : (string) $row['read_through_post_id'],
			readThroughAt: $row['read_through_at'] === null ? null : Time::parse((string) $row['read_through_at']),
			dismissedThroughPostId: $row['dismissed_through_post_id'] === null ? null : (string) $row['dismissed_through_post_id'],
			dismissedThroughAt: $row['dismissed_through_at'] === null ? null : Time::parse((string) $row['dismissed_through_at']),
		);
	}

	public function save(ConversationMarker $marker): void
	{
		$parameters = [
			'id' => $marker->id,
			'user_id' => $marker->userId,
			'conversation_id' => $marker->conversationId,
			'read_through_post_id' => $marker->readThroughPostId,
			'read_through_at' => $marker->readThroughAt === null ? null : Time::format($marker->readThroughAt),
			'dismissed_through_post_id' => $marker->dismissedThroughPostId,
			'dismissed_through_at' => $marker->dismissedThroughAt === null ? null : Time::format($marker->dismissedThroughAt),
		];
		$updateParameters = $parameters;
		unset($updateParameters['user_id'], $updateParameters['conversation_id']);
		try {
			$changed = $this->db->execute(
				'UPDATE ' . ConversationMarkerSchema::TABLE
				. ' SET read_through_post_id = :read_through_post_id, read_through_at = :read_through_at,'
				. ' dismissed_through_post_id = :dismissed_through_post_id, dismissed_through_at = :dismissed_through_at'
				. ' WHERE id = :id',
				$updateParameters,
			);
			if ($changed !== 0 || $this->find($marker->userId, $marker->conversationId) !== null) {
				return;
			}
			$this->db->execute(
				'INSERT INTO ' . ConversationMarkerSchema::TABLE
				. ' (id, user_id, conversation_id, read_through_post_id, read_through_at, dismissed_through_post_id, dismissed_through_at)'
				. ' VALUES (:id, :user_id, :conversation_id, :read_through_post_id, :read_through_at, :dismissed_through_post_id, :dismissed_through_at)',
				$parameters,
			);
		} catch (PDOException $exception) {
			$existing = $this->find($marker->userId, $marker->conversationId);
			if ($existing === null) {
				throw new DatabaseException('Conversation marker could not be saved.', 0, $exception);
			}
			$this->db->execute(
				'UPDATE ' . ConversationMarkerSchema::TABLE
				. ' SET read_through_post_id = :read_through_post_id, read_through_at = :read_through_at,'
				. ' dismissed_through_post_id = :dismissed_through_post_id, dismissed_through_at = :dismissed_through_at'
				. ' WHERE id = :id',
				$updateParameters,
			);
		}
	}
}
