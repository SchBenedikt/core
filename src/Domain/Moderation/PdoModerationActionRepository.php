<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * SQL adapter for actor/domain policy actions.
 *
 * Active means `reversed_at IS NULL`. Reversal stamps the row; history reads
 * include reversed rows.
 */
final class PdoModerationActionRepository implements ModerationActionRepository
{
	private const COLUMNS = 'id, kind, target, report_id, moderator_id, note, created_at,'
		. ' reversed_at, reversed_by, reversal_note, public_comment';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(ModerationAction $action): void
	{
		$this->db->execute(
			'INSERT INTO ' . ModerationActionSchema::TABLE
			. ' (' . self::COLUMNS . ') VALUES (:id, :kind, :target, :report_id, :moderator_id, :note,'
			. ' :created_at, :reversed_at, :reversed_by, :reversal_note, :public_comment)',
			[
				'id' => $action->id,
				'kind' => $action->kind->value,
				'target' => $action->target,
				'report_id' => $action->reportId,
				'moderator_id' => $action->moderatorId,
				'note' => $action->note,
				'created_at' => Time::format($action->createdAt),
				'reversed_at' => $action->reversedAt === null ? null : Time::format($action->reversedAt),
				'reversed_by' => $action->reversedBy,
				'reversal_note' => $action->reversalNote,
				'public_comment' => $action->publicComment,
			],
		);
	}

	public function find(string $id): ?ModerationAction
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . ModerationActionSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findActive(ModerationActionKind $kind, string $target): ?ModerationAction
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . ModerationActionSchema::TABLE
			. ' WHERE kind = :kind AND target = :target AND reversed_at IS NULL'
			. ' ORDER BY created_at DESC, id DESC LIMIT 1',
			['kind' => $kind->value, 'target' => $target],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function active(?ModerationActionKind $kind = null, int $limit = 100): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . ModerationActionSchema::TABLE . ' WHERE reversed_at IS NULL';
		$parameters = [];
		if ($kind !== null) {
			$sql .= ' AND kind = :kind';
			$parameters['kind'] = $kind->value;
		}

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

		return array_map(fn (array $row): ModerationAction => $this->hydrate($row), $this->db->fetchAll($sql, $parameters));
	}

	public function historyFor(string $target, int $limit = 100): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . ModerationActionSchema::TABLE
			. ' WHERE target = :target ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
			['target' => $target],
		);

		return array_map(fn (array $row): ModerationAction => $this->hydrate($row), $rows);
	}

	public function reverse(string $id, string $moderatorId, string $note, DateTimeImmutable $at): bool
	{
		return $this->db->execute(
			'UPDATE ' . ModerationActionSchema::TABLE
			. ' SET reversed_at = :at, reversed_by = :by, reversal_note = :note'
			. ' WHERE id = :id AND reversed_at IS NULL',
			['id' => $id, 'at' => Time::format($at), 'by' => $moderatorId, 'note' => $note],
		) > 0;
	}

	public function isDomainBlocked(string $domain): bool
	{
		return $this->findActive(ModerationActionKind::BlockDomain, $domain) !== null;
	}

	public function isActorRestricted(string $actorId): bool
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . ModerationActionSchema::TABLE
			. " WHERE target = :target AND reversed_at IS NULL AND kind IN ('silence_actor', 'suspend_actor')",
			['target' => $actorId],
		);

		return (int) $value > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): ModerationAction
	{
		return new ModerationAction(
			id: (string) $row['id'],
			kind: ModerationActionKind::from((string) $row['kind']),
			target: (string) $row['target'],
			reportId: $row['report_id'] === null ? null : (string) $row['report_id'],
			moderatorId: (string) $row['moderator_id'],
			note: $row['note'] === null ? null : (string) $row['note'],
			createdAt: Time::parse((string) $row['created_at']),
			reversedAt: $row['reversed_at'] === null ? null : Time::parse((string) $row['reversed_at']),
			reversedBy: $row['reversed_by'] === null ? null : (string) $row['reversed_by'],
			reversalNote: $row['reversal_note'] === null ? null : (string) $row['reversal_note'],
			publicComment: $row['public_comment'] === null ? null : (string) $row['public_comment'],
		);
	}
}
