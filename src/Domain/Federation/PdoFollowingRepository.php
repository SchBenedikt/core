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
use PDOException;

/**
 * The outgoing follow graph in SQL.
 *
 * Timestamps use the canonical fixed-width form, so `followed_at` compares
 * chronologically as text and the collection query is one indexed scan.
 */
final class PdoFollowingRepository implements FollowingRepository
{
	private const COLUMNS = 'id, local_user_id, actor_id, inbox_url, followed_at, state, follow_activity_id';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(FollowedActor $actor): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . FollowingSchema::TABLE
				. ' (id, local_user_id, actor_id, inbox_url, followed_at, state, follow_activity_id)'
				. ' VALUES (:id, :local_user_id, :actor_id, :inbox_url, :followed_at, :state, :follow_activity_id)',
				[
					'id' => $actor->id,
					'local_user_id' => $actor->localUserId,
					'actor_id' => $actor->actorId,
					'inbox_url' => $actor->inboxUrl,
					'followed_at' => Time::format($actor->followedAt),
					'state' => $actor->state->value,
					'follow_activity_id' => $actor->followActivityId,
				],
			);
		} catch (PDOException $exception) {
			// The service turns a duplicate pair into an idempotent result.
			throw new DatabaseException('The followed actor could not be stored.', 0, $exception);
		}
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

	public function findByActivityId(string $activityId): ?FollowedActor
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowingSchema::TABLE
			. ' WHERE follow_activity_id = :activity_id',
			['activity_id' => $activityId],
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

	public function updateState(string $id, FollowState $expected, FollowState $state): bool
	{
		return $this->db->execute(
			'UPDATE ' . FollowingSchema::TABLE
			. ' SET state = :state WHERE id = :id AND state = :expected',
			['id' => $id, 'state' => $state->value, 'expected' => $expected->value],
		) > 0;
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
			. " WHERE local_user_id = :local_user_id AND state = 'accepted'"
			. ' ORDER BY followed_at DESC, id DESC',
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . FollowingSchema::TABLE
			. " WHERE local_user_id = :local_user_id AND state = 'accepted'"
			. ' ORDER BY followed_at DESC, id DESC'
			. ' LIMIT ' . max(1, $limit)
			. ' OFFSET ' . max(0, $offset),
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function followedActors(string $localUserId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowingSchema::TABLE
			. " WHERE local_user_id = :local_user_id AND state = 'accepted'"
			. ' ORDER BY followed_at DESC, id DESC',
			['local_user_id' => $localUserId],
		);

		return array_map(fn (array $row): FollowedActor => $this->hydrate($row), $rows);
	}

	public function count(string $localUserId): int
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowingSchema::TABLE
			. " WHERE local_user_id = :local_user_id AND state = 'accepted'",
			['local_user_id' => $localUserId],
		);

		return (int) $value;
	}

	public function followedActorsForActor(string $actorId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowingSchema::TABLE
			. ' WHERE actor_id = :actor_id AND state IN (:accepted, :pending)'
			. ' ORDER BY followed_at DESC, id DESC'
			. ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
			[
				'actor_id' => $actorId,
				'accepted' => FollowState::Accepted->value,
				'pending' => FollowState::Pending->value,
			],
		);

		return array_map(fn (array $row): FollowedActor => $this->hydrate($row), $rows);
	}

	public function localUserIdsForActor(string $actorId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT DISTINCT local_user_id FROM ' . FollowingSchema::TABLE
			. " WHERE actor_id = :actor_id AND state = 'accepted' ORDER BY local_user_id",
			['actor_id' => $actorId],
		);

		return array_map(static fn (array $row): string => (string) $row['local_user_id'], $rows);
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
			state: FollowState::fromStored($row['state'] ?? null),
			followActivityId: $row['follow_activity_id'] === null
				? null
				: (string) $row['follow_activity_id'],
		);
	}
}
