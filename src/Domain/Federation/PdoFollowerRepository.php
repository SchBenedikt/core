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

/**
 * The follow graph in SQL.
 *
 * Timestamps use the canonical fixed-width form, so `followed_at` compares
 * chronologically as text and the cursor query is one indexed range scan.
 */
final class PdoFollowerRepository implements FollowerRepository
{
	private const COLUMNS = 'id, local_user_id, actor_id, inbox_url, shared_inbox_url, followed_at, is_local';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Follower $follower): void
	{
		$this->db->execute(
			'INSERT INTO ' . FollowerSchema::TABLE
			. ' (id, local_user_id, actor_id, inbox_url, shared_inbox_url, followed_at, is_local)'
			. ' VALUES (:id, :local_user_id, :actor_id, :inbox_url, :shared_inbox_url, :followed_at, :is_local)',
			[
				'id' => $follower->id,
				'local_user_id' => $follower->localUserId,
				'actor_id' => $follower->actorId,
				'inbox_url' => $follower->inboxUrl,
				'shared_inbox_url' => $follower->sharedInboxUrl,
				'followed_at' => Time::format($follower->followedAt),
				'is_local' => $follower->local ? 1 : 0,
			],
		);
	}

	public function updateInbox(Follower $follower): void
	{
		$this->db->execute(
			'UPDATE ' . FollowerSchema::TABLE
			. ' SET inbox_url = :inbox_url, shared_inbox_url = :shared_inbox_url WHERE id = :id',
			[
				'id' => $follower->id,
				'inbox_url' => $follower->inboxUrl,
				'shared_inbox_url' => $follower->sharedInboxUrl,
			],
		);
	}

	public function find(string $id): ?Follower
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findByActor(string $localUserId, string $actorId): ?Follower
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function remove(string $id, string $localUserId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . FollowerSchema::TABLE
			. ' WHERE id = :id AND local_user_id = :local_user_id',
			['id' => $id, 'local_user_id' => $localUserId],
		) > 0;
	}

	public function removeByActor(string $localUserId, string $actorId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		) > 0;
	}

	public function followers(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id';
		$parameters = ['local_user_id' => $localUserId];

		if ($before !== null) {
			$sql .= ' AND followed_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY followed_at DESC, id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): Follower => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
	}

	public function actorIds(string $localUserId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id'
			. ' ORDER BY followed_at DESC, id DESC',
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id'
			. ' ORDER BY followed_at DESC, id DESC'
			. ' LIMIT ' . max(1, $limit)
			. ' OFFSET ' . max(0, $offset),
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function localFollowsByActor(string $actorId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND is_local = 1 ORDER BY followed_at DESC, id DESC',
			['actor_id' => $actorId],
		);

		return array_map(fn (array $row): Follower => $this->hydrate($row), $rows);
	}

	public function localUserIdsFollowedBy(string $actorId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT DISTINCT local_user_id FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND is_local = 1 ORDER BY local_user_id',
			['actor_id' => $actorId],
		);

		return array_map(static fn (array $row): string => (string) $row['local_user_id'], $rows);
	}

	public function localFollowingCount(string $actorId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND is_local = 1',
			['actor_id' => $actorId],
		);
	}

	public function inboxesFor(string $localUserId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT DISTINCT COALESCE(shared_inbox_url, inbox_url) AS inbox_url'
			. ' FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND is_local = 0 ORDER BY inbox_url',
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['inbox_url'], $rows);
	}

	public function count(string $localUserId): int
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowerSchema::TABLE . ' WHERE local_user_id = :local_user_id',
			['local_user_id' => $localUserId],
		);

		return (int) $value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Follower
	{
		return new Follower(
			id: (string) $row['id'],
			localUserId: (string) $row['local_user_id'],
			actorId: (string) $row['actor_id'],
			inboxUrl: (string) $row['inbox_url'],
			followedAt: Time::parse((string) $row['followed_at']),
			sharedInboxUrl: $row['shared_inbox_url'] === null ? null : (string) $row['shared_inbox_url'],
			local: (int) ($row['is_local'] ?? 0) === 1,
		);
	}
}
