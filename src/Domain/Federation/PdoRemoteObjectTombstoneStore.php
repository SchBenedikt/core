<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

use function max;

/**
 * The delete-before-create guard in SQL.
 *
 * Expiry is enforced on every write and again at read time through the
 * `deleted_at` cutoff, and the row cap is applied with an inlined integer
 * limit: binding a LIMIT is not portable across the supported drivers, and
 * the value is computed here rather than taken from a caller.
 */
final class PdoRemoteObjectTombstoneStore implements RemoteObjectTombstoneStore
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function record(
		string $actorId,
		string $objectId,
		string $activityId,
		DateTimeImmutable $deletedAt,
	): void {
		$cutoff = $deletedAt->modify('-' . RemoteObjectTombstoneStore::TTL_SECONDS . ' seconds');

		$this->db->transaction(function (Connection $db) use ($actorId, $objectId, $activityId, $deletedAt, $cutoff): void {
			$db->execute(
				'DELETE FROM ' . RemoteObjectTombstoneSchema::TABLE . ' WHERE deleted_at < :cutoff',
				['cutoff' => Time::format($cutoff)],
			);

			$exists = $db->fetchValue(
				'SELECT 1 FROM ' . RemoteObjectTombstoneSchema::TABLE
				. ' WHERE actor_id = :actor_id AND object_id = :object_id',
				['actor_id' => $actorId, 'object_id' => $objectId],
			);

			if ($exists !== null) {
				// The earliest deletion is the one that counts.
				return;
			}

			try {
				$db->execute(
					'INSERT INTO ' . RemoteObjectTombstoneSchema::TABLE
					. ' (actor_id, object_id, activity_id, deleted_at)'
					. ' VALUES (:actor_id, :object_id, :activity_id, :deleted_at)',
					[
						'actor_id' => $actorId,
						'object_id' => $objectId,
						'activity_id' => $activityId,
						'deleted_at' => Time::format($deletedAt),
					],
				);
			} catch (Throwable $exception) {
				// A concurrent Delete of the same pair lost the race; the
				// winner's row already says what this one would have.
				$existing = $db->fetchValue(
					'SELECT 1 FROM ' . RemoteObjectTombstoneSchema::TABLE
					. ' WHERE actor_id = :actor_id AND object_id = :object_id',
					['actor_id' => $actorId, 'object_id' => $objectId],
				);

				if ($existing === null) {
					if ($exception instanceof DatabaseException) {
						throw $exception;
					}

					throw new DatabaseException('The remote object tombstone could not be stored.', 0, $exception);
				}
			}

			$total = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . RemoteObjectTombstoneSchema::TABLE);
			$excess = $total - RemoteObjectTombstoneStore::MAX_ENTRIES;
			if ($excess > 0) {
				$db->execute(
					'DELETE FROM ' . RemoteObjectTombstoneSchema::TABLE
					. ' WHERE (actor_id, object_id) IN ('
					. 'SELECT actor_id, object_id FROM ' . RemoteObjectTombstoneSchema::TABLE
					. ' ORDER BY deleted_at ASC, actor_id ASC, object_id ASC LIMIT ' . max(0, $excess)
					. ')',
				);
			}
		});
	}

	public function isDeleted(string $actorId, string $objectId, DateTimeImmutable $now): bool
	{
		$cutoff = $now->modify('-' . RemoteObjectTombstoneStore::TTL_SECONDS . ' seconds');

		return $this->db->fetchValue(
			'SELECT 1 FROM ' . RemoteObjectTombstoneSchema::TABLE
			. ' WHERE actor_id = :actor_id AND object_id = :object_id AND deleted_at >= :cutoff',
			['actor_id' => $actorId, 'object_id' => $objectId, 'cutoff' => Time::format($cutoff)],
		) !== null;
	}
}
