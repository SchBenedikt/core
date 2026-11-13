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

use function array_filter;
use function array_map;
use function array_values;

/**
 * Private per-user account mutes in SQL.
 *
 * The composite key is the upsert boundary, so muting twice updates one row.
 * The same fixed-width timestamps as the rest of the core make the cursor
 * comparison portable across SQLite, MySQL and PostgreSQL.
 */
final class PdoMuteRepository implements MuteRepository
{
	private const COLUMNS = 'user_id, actor_id, notifications, created_at, expires_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function save(Mute $mute): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . MuteSchema::TABLE
				. ' (user_id, actor_id, notifications, created_at, expires_at)'
				. ' VALUES (:user_id, :actor_id, :notifications, :created_at, :expires_at)',
				[
					'user_id' => $mute->userId,
					'actor_id' => $mute->actorId,
					'notifications' => $mute->notifications ? 1 : 0,
					'created_at' => Time::format($mute->createdAt),
					'expires_at' => $mute->expiresAt === null ? null : Time::format($mute->expiresAt),
				],
			);

			return;
		} catch (Throwable $exception) {
			// The pair already exists: update it instead. A missing table also
			// lands here, so the update below is the honest failure detector.
			try {
				$changed = $this->db->execute(
					'UPDATE ' . MuteSchema::TABLE
					. ' SET notifications = :notifications, created_at = :created_at,'
					. ' expires_at = :expires_at'
					. ' WHERE user_id = :user_id AND actor_id = :actor_id',
					[
						'notifications' => $mute->notifications ? 1 : 0,
						'created_at' => Time::format($mute->createdAt),
						'expires_at' => $mute->expiresAt === null ? null : Time::format($mute->expiresAt),
						'user_id' => $mute->userId,
						'actor_id' => $mute->actorId,
					],
				);
			} catch (Throwable $updateFailure) {
				throw new DatabaseException('The mute could not be stored.', 0, $updateFailure);
			}

			if ($changed === 0 && !$this->exists($mute->userId, $mute->actorId)) {
				throw new DatabaseException('The mute could not be stored.', 0, $exception);
			}
		}
	}

	public function remove(string $userId, string $actorId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . MuteSchema::TABLE
			. ' WHERE user_id = :user_id AND actor_id = :actor_id',
			['user_id' => $userId, 'actor_id' => $actorId],
		) > 0;
	}

	public function find(string $userId, string $actorId): ?Mute
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . MuteSchema::TABLE
			. ' WHERE user_id = :user_id AND actor_id = :actor_id',
			['user_id' => $userId, 'actor_id' => $actorId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function forUser(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeActorId = null,
		?DateTimeImmutable $after = null,
		?string $afterActorId = null,
		?DateTimeImmutable $now = null,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . MuteSchema::TABLE
			. ' WHERE user_id = :user_id';
		$parameters = ['user_id' => $userId];

		if ($now !== null) {
			$sql .= ' AND (expires_at IS NULL OR expires_at > :now)';
			$parameters['now'] = Time::format($now);
		}

		if ($before !== null) {
			if ($beforeActorId === null) {
				$sql .= ' AND created_at < :before';
			} else {
				$sql .= ' AND (created_at < :before OR (created_at = :before AND actor_id < :before_actor))';
				$parameters['before_actor'] = $beforeActorId;
			}
			$parameters['before'] = Time::format($before);
		}

		if ($after !== null) {
			if ($afterActorId === null) {
				$sql .= ' AND created_at > :after';
			} else {
				$sql .= ' AND (created_at > :after OR (created_at = :after AND actor_id > :after_actor))';
				$parameters['after_actor'] = $afterActorId;
			}
			$parameters['after'] = Time::format($after);
		}

		$sql .= ' ORDER BY created_at DESC, actor_id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): Mute => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
	}

	public function actorIds(string $userId, ?DateTimeImmutable $now = null): array
	{
		return array_map(
			static fn (Mute $mute): string => $mute->actorId,
			$this->forUser($userId, PHP_INT_MAX, null, null, null, null, $now),
		);
	}

	public function notificationActorIds(string $userId, ?DateTimeImmutable $now = null): array
	{
		return array_map(
			static fn (Mute $mute): string => $mute->actorId,
			array_values(array_filter(
				$this->forUser($userId, PHP_INT_MAX, null, null, null, null, $now),
				static fn (Mute $mute): bool => $mute->notifications,
			)),
		);
	}

	private function exists(string $userId, string $actorId): bool
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . MuteSchema::TABLE
			. ' WHERE user_id = :user_id AND actor_id = :actor_id',
			['user_id' => $userId, 'actor_id' => $actorId],
		) > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Mute
	{
		return new Mute(
			userId: (string) $row['user_id'],
			actorId: (string) $row['actor_id'],
			notifications: (int) $row['notifications'] === 1,
			createdAt: Time::parse((string) $row['created_at']),
			expiresAt: $row['expires_at'] === null ? null : Time::parse((string) $row['expires_at']),
		);
	}
}
