<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use DateTimeImmutable;

/**
 * Durable report storage and the small state machine a reviewer drives.
 *
 * Every transition is conditional on the current status, so two moderators
 * cannot both move one report and apply two actions.
 */
interface ReportRepository
{
	public function insert(Report $report): void;

	public function find(string $id): ?Report;

	/**
	 * An open report by the same reporter about the same target; used to keep
	 * a repeated submit from filling the queue with duplicates.
	 */
	public function findOpenByReporter(string $reporterId, ReportTargetType $targetType, string $targetId): ?Report;

	/**
	 * Oldest open reports first, with the id as the tie-break.
	 *
	 * @return list<Report>
	 */
	public function queue(int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * Newest reports by one reporter.
	 *
	 * @return list<Report>
	 */
	public function forReporter(string $reporterId, int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * pending -> reviewing. False means another reviewer won the claim.
	 */
	public function claimForReview(string $id, string $moderatorId, DateTimeImmutable $at): bool;

	/**
	 * reviewing -> resolved/rejected. False means the row was not claimed.
	 */
	public function complete(
		string $id,
		ReportStatus $status,
		?ModerationDecision $decision,
		string $moderatorId,
		?string $resolution,
		DateTimeImmutable $at,
	): bool;

	/**
	 * reviewing -> pending after a failed action write.
	 */
	public function release(string $id): void;

	public function countOpen(): int;
}
