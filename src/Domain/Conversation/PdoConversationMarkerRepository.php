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
		if ($this->find($marker->userId, $marker->conversationId) === null) {
			try {
				$this->db->execute(
					'INSERT INTO ' . ConversationMarkerSchema::TABLE
					. ' (id, user_id, conversation_id, read_through_post_id, read_through_at, dismissed_through_post_id, dismissed_through_at)'
					. ' VALUES (?, ?, ?, ?, ?, ?, ?)',
					[
						$marker->id,
						$marker->userId,
						$marker->conversationId,
						$marker->readThroughPostId,
						$marker->readThroughAt === null ? null : Time::format($marker->readThroughAt),
						$marker->dismissedThroughPostId,
						$marker->dismissedThroughAt === null ? null : Time::format($marker->dismissedThroughAt),
					],
				);
			} catch (PDOException $exception) {
				if ($this->find($marker->userId, $marker->conversationId) === null) {
					throw new DatabaseException('Conversation marker could not be saved.', 0, $exception);
				}
			}
		}

		$this->advance('read_through_post_id', 'read_through_at', $marker->id, $marker->readThroughPostId, $marker->readThroughAt);
		$this->advance('dismissed_through_post_id', 'dismissed_through_at', $marker->id, $marker->dismissedThroughPostId, $marker->dismissedThroughAt);
	}

	private function advance(string $postColumn, string $timeColumn, string $id, ?string $postId, ?\DateTimeImmutable $at): void
	{
		if ($postId === null || $at === null) {
			return;
		}

		$timestamp = Time::format($at);
		try {
			$this->db->execute(
				'UPDATE ' . ConversationMarkerSchema::TABLE
				. ' SET ' . $postColumn . ' = ?, ' . $timeColumn . ' = ?'
				. ' WHERE id = ? AND (' . $timeColumn . ' IS NULL OR ' . $timeColumn . ' < ?'
				. ' OR (' . $timeColumn . ' = ? AND ' . $postColumn . ' < ?))',
				[$postId, $timestamp, $id, $timestamp, $timestamp, $postId],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('Conversation marker could not be advanced.', 0, $exception);
		}
	}
}
