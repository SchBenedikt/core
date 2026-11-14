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
	private const COLUMNS = 'id, local_user_id, actor_id, inbox_url, shared_inbox_url, followed_at, is_local, state, follow_activity';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Follower $follower): void
	{
		$this->db->execute(
			'INSERT INTO ' . FollowerSchema::TABLE
			. ' (id, local_user_id, actor_id, inbox_url, shared_inbox_url, followed_at, is_local, state, follow_activity)'
			. ' VALUES (:id, :local_user_id, :actor_id, :inbox_url, :shared_inbox_url, :followed_at, :is_local, :state, :follow_activity)',
			[
				'id' => $follower->id,
				'local_user_id' => $follower->localUserId,
				'actor_id' => $follower->actorId,
				'inbox_url' => $follower->inboxUrl,
				'shared_inbox_url' => $follower->sharedInboxUrl,
				'followed_at' => Time::format($follower->followedAt),
				'is_local' => $follower->local ? 1 : 0,
				'state' => $follower->state->value,
				'follow_activity' => $follower->followActivity === []
					? null
					: json_encode($follower->followActivity, JSON_THROW_ON_ERROR),
			],
		);
	}

	public function updateInbox(Follower $follower): void
	{
		$this->db->execute(
			'UPDATE ' . FollowerSchema::TABLE
			. ' SET inbox_url = :inbox_url, shared_inbox_url = :shared_inbox_url,'
			. ' follow_activity = :follow_activity WHERE id = :id',
			[
				'id' => $follower->id,
				'inbox_url' => $follower->inboxUrl,
				'shared_inbox_url' => $follower->sharedInboxUrl,
				'follow_activity' => $follower->followActivity === []
					? null
					: json_encode($follower->followActivity, JSON_THROW_ON_ERROR),
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

	public function updateState(
		string $id,
		string $localUserId,
		FollowState $from,
		FollowState $to,
	): bool {
		if ($to === FollowState::Rejected) {
			return $this->db->execute(
				'DELETE FROM ' . FollowerSchema::TABLE
				. ' WHERE id = :id AND local_user_id = :local_user_id AND state = :state',
				['id' => $id, 'local_user_id' => $localUserId, 'state' => $from->value],
			) > 0;
		}

		return $this->db->execute(
			'UPDATE ' . FollowerSchema::TABLE . ' SET state = :to WHERE id = :id'
			. ' AND local_user_id = :local_user_id AND state = :from',
			[
				'to' => $to->value,
				'id' => $id,
				'local_user_id' => $localUserId,
				'from' => $from->value,
			],
		) > 0;
	}

	public function pending(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND state = :state';
		$parameters = ['local_user_id' => $localUserId, 'state' => FollowState::Pending->value];
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
			. ' WHERE local_user_id = :local_user_id AND state = :state';
		$parameters = ['local_user_id' => $localUserId, 'state' => FollowState::Accepted->value];

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
			. ' WHERE local_user_id = :local_user_id AND state = :state'
			. ' ORDER BY followed_at DESC, id DESC',
			['local_user_id' => $localUserId, 'state' => FollowState::Accepted->value],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND state = :state'
			. ' ORDER BY followed_at DESC, id DESC'
			. ' LIMIT ' . max(1, $limit)
			. ' OFFSET ' . max(0, $offset),
			['local_user_id' => $localUserId, 'state' => FollowState::Accepted->value],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function localFollowsByActor(string $actorId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND is_local = 1 AND state = :state'
			. ' ORDER BY followed_at DESC, id DESC',
			['actor_id' => $actorId, 'state' => FollowState::Accepted->value],
		);

		return array_map(fn (array $row): Follower => $this->hydrate($row), $rows);
	}

	public function localUserIdsFollowedBy(string $actorId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT DISTINCT local_user_id FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND is_local = 1 AND state = :state'
			. ' ORDER BY local_user_id',
			['actor_id' => $actorId, 'state' => FollowState::Accepted->value],
		);

		return array_map(static fn (array $row): string => (string) $row['local_user_id'], $rows);
	}

	public function localFollowingCount(string $actorId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND is_local = 1 AND state = :state',
			['actor_id' => $actorId, 'state' => FollowState::Accepted->value],
		);
	}

	public function inboxesFor(string $localUserId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT DISTINCT COALESCE(shared_inbox_url, inbox_url) AS inbox_url'
			. ' FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND is_local = 0 AND state = :state'
			. ' ORDER BY inbox_url',
			['local_user_id' => $localUserId, 'state' => FollowState::Accepted->value],
		);

		return array_map(static fn (array $row): string => (string) $row['inbox_url'], $rows);
	}

	public function followersForActor(string $actorId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . FollowerSchema::TABLE
			. ' WHERE actor_id = :actor_id AND state IN (:accepted, :pending)'
			. ' ORDER BY followed_at DESC, id DESC'
			. ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
			[
				'actor_id' => $actorId,
				'accepted' => FollowState::Accepted->value,
				'pending' => FollowState::Pending->value,
			],
		);

		return array_map(fn (array $row): Follower => $this->hydrate($row), $rows);
	}

	public function replaceActor(
		string $id,
		string $localUserId,
		string $oldActorId,
		string $newActorId,
		string $inboxUrl,
		?string $sharedInboxUrl,
	): bool {
		return $this->db->execute(
			'UPDATE ' . FollowerSchema::TABLE . ' SET actor_id = :new_actor_id, inbox_url = :inbox_url,'
			. ' shared_inbox_url = :shared_inbox_url, follow_activity = NULL'
			. ' WHERE id = :id AND local_user_id = :local_user_id AND actor_id = :old_actor_id',
			[
				'new_actor_id' => $newActorId,
				'inbox_url' => $inboxUrl,
				'shared_inbox_url' => $sharedInboxUrl,
				'id' => $id,
				'local_user_id' => $localUserId,
				'old_actor_id' => $oldActorId,
			],
		) > 0;
	}

	public function count(string $localUserId): int
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowerSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND state = :state',
			['local_user_id' => $localUserId, 'state' => FollowState::Accepted->value],
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
			state: FollowState::tryFrom((string) ($row['state'] ?? FollowState::Accepted->value))
				?? FollowState::Accepted,
			followActivity: $this->decodeActivity($row['follow_activity'] ?? null),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeActivity(mixed $value): array
	{
		if (!is_string($value) || $value === '') {
			return [];
		}

		$decoded = json_decode($value, true);

		return is_array($decoded) ? $decoded : [];
	}
}
