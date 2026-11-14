<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function max;

/**
 * The early-update buffer in SQL.
 *
 * Expiry is enforced on every write, and the row cap is applied with an
 * inlined integer limit: binding a LIMIT is not portable across the supported
 * drivers, and the value is computed here rather than taken from a caller.
 */
final class PdoPendingRemoteUpdateStore implements PendingRemoteUpdateStore
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function store(PendingRemoteUpdate $update): void
	{
		$cutoff = $update->receivedAt->modify('-' . PendingRemoteUpdateStore::TTL_SECONDS . ' seconds');

		$this->db->transaction(function (Connection $db) use ($update, $cutoff): void {
			$db->execute(
				'DELETE FROM ' . PendingRemoteUpdateSchema::TABLE . ' WHERE received_at < :cutoff',
				['cutoff' => Time::format($cutoff)],
			);

			$db->execute(
				'DELETE FROM ' . PendingRemoteUpdateSchema::TABLE . ' WHERE object_id = :object_id',
				['object_id' => $update->objectId],
			);

			$db->execute(
				'INSERT INTO ' . PendingRemoteUpdateSchema::TABLE
				. ' (object_id, actor_id, activity_id, content, content_warning, updated_at, received_at)'
				. ' VALUES (:object_id, :actor_id, :activity_id, :content, :content_warning, :updated_at, :received_at)',
				[
					'object_id' => $update->objectId,
					'actor_id' => $update->actorId,
					'activity_id' => $update->activityId,
					'content' => $update->content,
					'content_warning' => $update->contentWarning,
					'updated_at' => Time::format($update->updatedAt),
					'received_at' => Time::format($update->receivedAt),
				],
			);

			$total = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . PendingRemoteUpdateSchema::TABLE);
			$excess = $total - PendingRemoteUpdateStore::MAX_ENTRIES;
			if ($excess > 0) {
				$db->execute(
					'DELETE FROM ' . PendingRemoteUpdateSchema::TABLE
					. ' WHERE object_id IN ('
					. 'SELECT object_id FROM ' . PendingRemoteUpdateSchema::TABLE
					. ' ORDER BY received_at ASC, object_id ASC LIMIT ' . max(0, $excess)
					. ')',
				);
			}
		});
	}

	public function take(string $objectId, string $actorId, DateTimeImmutable $now): ?PendingRemoteUpdate
	{
		$row = $this->db->fetchOne(
			'SELECT object_id, actor_id, activity_id, content, content_warning, updated_at, received_at'
			. ' FROM ' . PendingRemoteUpdateSchema::TABLE . ' WHERE object_id = :object_id',
			['object_id' => $objectId],
		);

		if ($row === null) {
			return null;
		}

		// The entry is consumed either way: a mismatched actor must not be
		// able to retry against the same stored row.
		$this->db->execute(
			'DELETE FROM ' . PendingRemoteUpdateSchema::TABLE . ' WHERE object_id = :object_id',
			['object_id' => $objectId],
		);

		if ((string) $row['actor_id'] !== $actorId) {
			return null;
		}

		$receivedAt = Time::parse((string) $row['received_at']);
		if ($receivedAt->getTimestamp() + PendingRemoteUpdateStore::TTL_SECONDS < $now->getTimestamp()) {
			return null;
		}

		return new PendingRemoteUpdate(
			objectId: (string) $row['object_id'],
			actorId: (string) $row['actor_id'],
			activityId: (string) $row['activity_id'],
			content: (string) $row['content'],
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			updatedAt: Time::parse((string) $row['updated_at']),
			receivedAt: $receivedAt,
		);
	}
}
