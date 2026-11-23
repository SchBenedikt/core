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

	public function block(string $localUserId, string $actorId, string $activityId): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . InboundBlockSchema::TABLE . ' (local_user_id, actor_id, activity_id)'
				. ' VALUES (:local_user_id, :actor_id, :activity_id)',
				['local_user_id' => $localUserId, 'actor_id' => $actorId, 'activity_id' => $activityId],
			);
		} catch (Throwable $exception) {
			if ($this->isBlocked($localUserId, $actorId)) {
				// A replay or a second Block from this actor must not replace the
				// activity URI that is allowed to undo the stored relationship.
				return;
			}
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}
			throw new DatabaseException('The inbound block could not be stored.', 0, $exception);
		}
	}

	public function unblockByActivityId(string $actorId, string $activityId, ?string $localUserId = null): bool
	{
		$sql = 'DELETE FROM ' . InboundBlockSchema::TABLE
			. ' WHERE actor_id = :actor_id AND activity_id = :activity_id';
		$parameters = ['actor_id' => $actorId, 'activity_id' => $activityId];
		if ($localUserId !== null) {
			$sql .= ' AND local_user_id = :local_user_id';
			$parameters['local_user_id'] = $localUserId;
		}

		return $this->db->execute($sql, $parameters) > 0;
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
