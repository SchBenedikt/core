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

/**
 * Per-user block storage in SQL.
 *
 * The unique index over (local_user_id, actor_id) is the authority on
 * idempotence; timestamps use the canonical fixed-width form so the cursor
 * query is one indexed range scan.
 */
final class PdoBlockRepository implements BlockRepository
{
	private const COLUMNS = 'id, local_user_id, actor_id, blocked_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Block $block): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . BlockSchema::TABLE
				. ' (id, local_user_id, actor_id, blocked_at)'
				. ' VALUES (:id, :local_user_id, :actor_id, :blocked_at)',
				[
					'id' => $block->id,
					'local_user_id' => $block->localUserId,
					'actor_id' => $block->actorId,
					'blocked_at' => Time::format($block->blockedAt),
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The block could not be stored.', 0, $exception);
		}
	}

	public function findByActor(string $localUserId, string $actorId): ?Block
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . BlockSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND actor_id = :actor_id',
			['local_user_id' => $localUserId, 'actor_id' => $actorId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function remove(string $id, string $localUserId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . BlockSchema::TABLE
			. ' WHERE id = :id AND local_user_id = :local_user_id',
			['id' => $id, 'local_user_id' => $localUserId],
		) > 0;
	}

	public function blocks(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . BlockSchema::TABLE
			. ' WHERE local_user_id = :local_user_id';
		$parameters = ['local_user_id' => $localUserId];

		if ($before !== null) {
			$sql .= ' AND blocked_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY blocked_at DESC, id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): Block => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
	}

	public function actorIds(string $localUserId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . BlockSchema::TABLE
			. ' WHERE local_user_id = :local_user_id'
			. ' ORDER BY blocked_at DESC, id DESC',
			['local_user_id' => $localUserId],
		);

		return array_map(static fn (array $row): string => (string) $row['actor_id'], $rows);
	}

	public function count(string $localUserId): int
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . BlockSchema::TABLE . ' WHERE local_user_id = :local_user_id',
			['local_user_id' => $localUserId],
		);

		return (int) $value;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Block
	{
		return new Block(
			id: (string) $row['id'],
			localUserId: (string) $row['local_user_id'],
			actorId: (string) $row['actor_id'],
			blockedAt: Time::parse((string) $row['blocked_at']),
		);
	}
}
