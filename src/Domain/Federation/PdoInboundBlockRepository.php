<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use Throwable;

final class PdoInboundBlockRepository implements InboundBlockRepository
{
	public function __construct(private readonly Connection $db) {}

	public function block(string $localUserId, string $actorId): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . InboundBlockSchema::TABLE . ' (local_user_id, actor_id)'
				. ' VALUES (:local_user_id, :actor_id)',
				['local_user_id' => $localUserId, 'actor_id' => $actorId],
			);
		} catch (Throwable $exception) {
			if ($this->isBlocked($localUserId, $actorId)) {
				return;
			}
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}
			throw new DatabaseException('The inbound block could not be stored.', 0, $exception);
		}
	}

	public function unblock(string $localUserId, string $actorId): void
	{
		$this->db->execute(
			'DELETE FROM ' . InboundBlockSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		);
	}

	public function isBlocked(string $localUserId, string $actorId): bool
	{
		return $this->db->fetchOne(
			'SELECT 1 FROM ' . InboundBlockSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		) !== null;
	}
}
