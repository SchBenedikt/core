<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;

/**
 * The outgoing follow graph in SQL.
 *
 * Timestamps use the canonical fixed-width form, so `followed_at` compares
 * chronologically as text and the collection query is one indexed scan.
 */
final class PdoFollowingRepository implements FollowingRepository
{
	private const COLUMNS = 'id, local_user_id, actor_id, inbox_url, followed_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(FollowedActor $actor): void
	{
		$this->db->execute(
			'INSERT INTO ' . FollowingSchema::TABLE
			. ' (id, local_user_id, actor_id, inbox_url, followed_at)'
			. ' VALUES (:id, :local_user_id, :actor_id, :inbox_url, :followed_at)',
			[
				'id' => $actor->id,
				'local_user_id' => $actor->localUserId,
				'actor_id' => $actor->actorId,
				'inbox_url' => $actor->inboxUrl,
				'followed_at' => Time::format($actor->followedAt),
			],
		);
	}

	public function find(string $localUserId, string $actorId): ?FollowedActor
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowingSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findById(string $id): ?FollowedActor
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowingSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function updateInbox(FollowedActor $actor): void
	{
		$this->db->execute(
			'UPDATE ' . FollowingSchema::TABLE . ' SET inbox_url = :inbox_url WHERE id = :id',
			['id' => $actor->id, 'inbox_url' => $actor->inboxUrl],
		);
	}

	public function remove(string $localUserId, string $actorId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . FollowingSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		) > 0;
	}

	public function actorIds(string $localUserId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . FollowingSchema::TABLE
			. ' WHERE local_user_id = :local_user_id'
			. ' ORDER BY followed_at DESC, id DESC',
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function count(string $localUserId): int
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowingSchema::TABLE . ' WHERE local_user_id = :local_user_id',
			['local_user_id' => $localUserId],
		);

		return (int) $value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): FollowedActor
	{
		return new FollowedActor(
			id: (string) $row['id'],
			localUserId: (string) $row['local_user_id'],
			actorId: (string) $row['actor_id'],
			inboxUrl: (string) $row['inbox_url'],
			followedAt: Time::parse((string) $row['followed_at']),
		);
	}
}
