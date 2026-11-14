<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use DateTimeImmutable;

/**
 * Report storage for tests and the in-memory assembly.
 *
 * It mirrors the SQL adapter's conditional transitions; the same report
 * service runs against both without a branch.
 */
final class InMemoryReportRepository implements ReportRepository
{
	/**
	 * @var array<string, Report>
	 */
	private array $reports = [];

	public function insert(Report $report): void
	{
		$this->reports[$report->id] = $report;
	}

	public function find(string $id): ?Report
	{
		return $this->reports[$id] ?? null;
	}

	public function findOpenByReporter(string $reporterId, ReportTargetType $targetType, string $targetId): ?Report
	{
		foreach ($this->reports as $report) {
			if ($report->reporterId === $reporterId
				&& $report->targetType === $targetType
				&& $report->targetId === $targetId
				&& $report->isOpen()
			) {
				return $report;
			}
		}

		return null;
	}

	public function queue(int $limit, ?DateTimeImmutable $before = null): array
	{
		$reports = array_values(array_filter(
			$this->reports,
			static fn (Report $report): bool => $report->status === ReportStatus::Pending
				&& ($before === null || $report->createdAt < $before),
		));

		usort($reports, static function (Report $a, Report $b): int {
			$byTime = $a->createdAt <=> $b->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($a->id, $b->id);
		});

		return array_slice($reports, 0, max(1, $limit));
	}

	public function forReporter(string $reporterId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$reports = array_values(array_filter(
			$this->reports,
			static fn (Report $report): bool => $report->reporterId === $reporterId
				&& ($before === null || $report->createdAt < $before),
		));

		usort($reports, static function (Report $a, Report $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($reports, 0, max(1, $limit));
	}

	public function claimForReview(string $id, string $moderatorId, DateTimeImmutable $at): bool
	{
		$report = $this->reports[$id] ?? null;
		if ($report === null || $report->status !== ReportStatus::Pending) {
			return false;
		}

		$this->reports[$id] = new Report(
			id: $report->id,
			reporterId: $report->reporterId,
			targetType: $report->targetType,
			targetId: $report->targetId,
			reason: $report->reason,
			comment: $report->comment,
			evidence: $report->evidence,
			status: ReportStatus::Reviewing,
			decision: null,
			moderatorId: $moderatorId,
			resolution: null,
			createdAt: $report->createdAt,
			updatedAt: $at,
		);

		return true;
	}

	public function complete(
		string $id,
		ReportStatus $status,
		?ModerationDecision $decision,
		string $moderatorId,
		?string $resolution,
		DateTimeImmutable $at,
	): bool {
		$report = $this->reports[$id] ?? null;
		if ($report === null
			|| $report->status !== ReportStatus::Reviewing
			|| $report->moderatorId !== $moderatorId
		) {
			return false;
		}

		$this->reports[$id] = new Report(
			id: $report->id,
			reporterId: $report->reporterId,
			targetType: $report->targetType,
			targetId: $report->targetId,
			reason: $report->reason,
			comment: $report->comment,
			evidence: $report->evidence,
			status: $status,
			decision: $decision,
			moderatorId: $moderatorId,
			resolution: $resolution,
			createdAt: $report->createdAt,
			updatedAt: $at,
		);

		return true;
	}

	public function release(string $id): void
	{
		$report = $this->reports[$id] ?? null;
		if ($report === null || $report->status !== ReportStatus::Reviewing) {
			return;
		}

		$this->reports[$id] = new Report(
			id: $report->id,
			reporterId: $report->reporterId,
			targetType: $report->targetType,
			targetId: $report->targetId,
			reason: $report->reason,
			comment: $report->comment,
			evidence: $report->evidence,
			status: ReportStatus::Pending,
			decision: null,
			moderatorId: null,
			resolution: null,
			createdAt: $report->createdAt,
			updatedAt: $report->updatedAt,
		);
	}

	public function countOpen(): int
	{
		$count = 0;
		foreach ($this->reports as $report) {
			if ($report->isOpen()) {
				++$count;
			}
		}

		return $count;
	}
}
