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
use JsonException;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * SQL adapter for the report queue.
 *
 * Every state transition is a conditional UPDATE: the database decides which
 * reviewer wins, so two web requests cannot both apply an action.
 */
final class PdoReportRepository implements ReportRepository
{
	private const COLUMNS = 'id, reporter_id, target_type, target_id, reason, comment, evidence, status,'
		. ' decision, moderator_id, resolution, created_at, updated_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Report $report): void
	{
		$this->db->execute(
			'INSERT INTO ' . ReportSchema::TABLE
			. ' (' . self::COLUMNS . ') VALUES (:id, :reporter_id, :target_type, :target_id, :reason,'
			. ' :comment, :evidence, :status, :decision, :moderator_id, :resolution, :created_at, :updated_at)',
			[
				'id' => $report->id,
				'reporter_id' => $report->reporterId,
				'target_type' => $report->targetType->value,
				'target_id' => $report->targetId,
				'reason' => $report->reason->value,
				'comment' => $report->comment,
				'evidence' => json_encode($report->evidence, JSON_THROW_ON_ERROR),
				'status' => $report->status->value,
				'decision' => $report->decision?->value,
				'moderator_id' => $report->moderatorId,
				'resolution' => $report->resolution,
				'created_at' => Time::format($report->createdAt),
				'updated_at' => Time::format($report->updatedAt),
			],
		);
	}

	public function find(string $id): ?Report
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . ReportSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findOpenByReporter(string $reporterId, ReportTargetType $targetType, string $targetId): ?Report
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . ReportSchema::TABLE
			. " WHERE reporter_id = :reporter_id AND target_type = :target_type AND target_id = :target_id"
			. " AND status IN ('pending', 'reviewing')"
			. ' ORDER BY created_at DESC, id DESC LIMIT 1',
			['reporter_id' => $reporterId, 'target_type' => $targetType->value, 'target_id' => $targetId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function queue(int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . ReportSchema::TABLE . " WHERE status = 'pending'";
		$parameters = [];
		if ($before !== null) {
			$sql .= ' AND created_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . max(1, $limit);

		return array_map(fn (array $row): Report => $this->hydrate($row), $this->db->fetchAll($sql, $parameters));
	}

	public function forReporter(string $reporterId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . ReportSchema::TABLE . ' WHERE reporter_id = :reporter_id';
		$parameters = ['reporter_id' => $reporterId];
		if ($before !== null) {
			$sql .= ' AND created_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

		return array_map(fn (array $row): Report => $this->hydrate($row), $this->db->fetchAll($sql, $parameters));
	}

	public function claimForReview(string $id, string $moderatorId, DateTimeImmutable $at): bool
	{
		return $this->db->execute(
			'UPDATE ' . ReportSchema::TABLE
			. " SET status = 'reviewing', moderator_id = :moderator_id, updated_at = :at"
			. " WHERE id = :id AND status = 'pending'",
			['id' => $id, 'moderator_id' => $moderatorId, 'at' => Time::format($at)],
		) > 0;
	}

	public function complete(
		string $id,
		ReportStatus $status,
		?ModerationDecision $decision,
		string $moderatorId,
		?string $resolution,
		DateTimeImmutable $at,
	): bool {
		return $this->db->execute(
			'UPDATE ' . ReportSchema::TABLE
			. ' SET status = :status, decision = :decision, resolution = :resolution, updated_at = :at'
			. " WHERE id = :id AND status = 'reviewing' AND moderator_id = :moderator_id",
			[
				'id' => $id,
				'status' => $status->value,
				'decision' => $decision?->value,
				'resolution' => $resolution,
				'at' => Time::format($at),
				'moderator_id' => $moderatorId,
			],
		) > 0;
	}

	public function release(string $id): void
	{
		$this->db->execute(
			'UPDATE ' . ReportSchema::TABLE
			. " SET status = 'pending', moderator_id = NULL WHERE id = :id AND status = 'reviewing'",
			['id' => $id],
		);
	}

	public function countOpen(): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . ReportSchema::TABLE . " WHERE status IN ('pending', 'reviewing')",
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Report
	{
		try {
			$evidence = json_decode((string) $row['evidence'], true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$evidence = [];
		}

		return new Report(
			id: (string) $row['id'],
			reporterId: (string) $row['reporter_id'],
			targetType: ReportTargetType::from((string) $row['target_type']),
			targetId: (string) $row['target_id'],
			reason: ReportReason::from((string) $row['reason']),
			comment: $row['comment'] === null ? null : (string) $row['comment'],
			evidence: is_array($evidence) ? array_values(array_map('strval', $evidence)) : [],
			status: ReportStatus::from((string) $row['status']),
			decision: $row['decision'] === null ? null : ModerationDecision::from((string) $row['decision']),
			moderatorId: $row['moderator_id'] === null ? null : (string) $row['moderator_id'],
			resolution: $row['resolution'] === null ? null : (string) $row['resolution'],
			createdAt: Time::parse((string) $row['created_at']),
			updatedAt: Time::parse((string) $row['updated_at']),
		);
	}
}
