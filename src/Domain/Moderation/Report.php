<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One report against a local post, a local/remote actor or a domain.
 *
 * Evidence is optional and deliberately bounded: a federated Flag may carry
 * a short excerpt, but the core never copies a whole conversation into the
 * moderation queue. The reporter is a local user id or a remote actor URL;
 * both are opaque strings to this model.
 */
final readonly class Report
{
	public const MAX_REPORTER_LENGTH = 512;

	public const MAX_TARGET_LENGTH = 512;

	public const MAX_COMMENT_LENGTH = 2000;

	public const MAX_EVIDENCE_ITEMS = 20;

	public const MAX_EVIDENCE_LENGTH = 2000;

	public const MAX_RESOLUTION_LENGTH = 2000;

	/**
	 * @param list<string> $evidence
	 */
	public function __construct(
		public string $id,
		public string $reporterId,
		public ReportTargetType $targetType,
		public string $targetId,
		public ReportReason $reason,
		public ?string $comment,
		public array $evidence,
		public ReportStatus $status,
		public ?ModerationDecision $decision,
		public ?string $moderatorId,
		public ?string $resolution,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $updatedAt,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('A report needs a bounded id.');
		}

		if ($reporterId === '' || mb_strlen($reporterId) > self::MAX_REPORTER_LENGTH) {
			throw new InvalidArgumentException('A report needs a bounded reporter.');
		}

		if ($targetId === '' || mb_strlen($targetId) > self::MAX_TARGET_LENGTH) {
			throw new InvalidArgumentException('A report needs a bounded target.');
		}

		if ($comment !== null && mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
			throw new InvalidArgumentException('A report comment is too long.');
		}

		if (count($evidence) > self::MAX_EVIDENCE_ITEMS) {
			throw new InvalidArgumentException('A report has too many evidence entries.');
		}

		foreach ($evidence as $entry) {
			if (!is_string($entry) || mb_strlen($entry) > self::MAX_EVIDENCE_LENGTH) {
				throw new InvalidArgumentException('A report evidence entry is invalid.');
			}
		}

		if ($resolution !== null && mb_strlen($resolution) > self::MAX_RESOLUTION_LENGTH) {
			throw new InvalidArgumentException('A report resolution is too long.');
		}

		if ($status->isOpen() && $decision !== null) {
			throw new InvalidArgumentException('An open report cannot have a decision.');
		}

		if ($status === ReportStatus::Pending && $moderatorId !== null) {
			throw new InvalidArgumentException('A pending report cannot be claimed.');
		}

		if (!$status->isOpen() && $moderatorId === null) {
			throw new InvalidArgumentException('A finished report needs a moderator.');
		}

		if (!$status->isOpen() && $status === ReportStatus::Resolved && $decision === null) {
			throw new InvalidArgumentException('A resolved report needs a decision.');
		}
	}

	public function isOpen(): bool
	{
		return $this->status->isOpen();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'reporter_id' => $this->reporterId,
			'target_type' => $this->targetType->value,
			'target_id' => $this->targetId,
			'reason' => $this->reason->value,
			'comment' => $this->comment,
			'evidence' => $this->evidence,
			'status' => $this->status->value,
			'decision' => $this->decision?->value,
			'moderator_id' => $this->moderatorId,
			'resolution' => $this->resolution,
			'created_at' => Time::format($this->createdAt),
			'updated_at' => Time::format($this->updatedAt),
		];
	}
}
