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
 * A durable instance policy: a silenced/suspended actor or a blocked domain.
 *
 * Reversal keeps the row and stamps `reversed_at`; the audit trail therefore
 * survives an unblock, and a later reapplied action is a new row.
 */
final readonly class ModerationAction
{
	public const MAX_TARGET_LENGTH = 512;

	public const MAX_NOTE_LENGTH = 2000;

	public function __construct(
		public string $id,
		public ModerationActionKind $kind,
		public string $target,
		public ?string $reportId,
		public string $moderatorId,
		public ?string $note,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $reversedAt = null,
		public ?string $reversedBy = null,
		public ?string $reversalNote = null,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('A moderation action needs a bounded id.');
		}

		if ($target === '' || mb_strlen($target) > self::MAX_TARGET_LENGTH) {
			throw new InvalidArgumentException('A moderation action needs a bounded target.');
		}

		if ($moderatorId === '') {
			throw new InvalidArgumentException('A moderation action needs a moderator.');
		}

		if ($note !== null && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
			throw new InvalidArgumentException('A moderation note is too long.');
		}

		if (($reversedAt === null) !== ($reversedBy === null)) {
			throw new InvalidArgumentException('A moderation reversal needs a time and an actor.');
		}
	}

	public function isActive(): bool
	{
		return $this->reversedAt === null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'kind' => $this->kind->value,
			'target' => $this->target,
			'report_id' => $this->reportId,
			'moderator_id' => $this->moderatorId,
			'note' => $this->note,
			'created_at' => Time::format($this->createdAt),
			'reversed_at' => $this->reversedAt === null ? null : Time::format($this->reversedAt),
			'reversed_by' => $this->reversedBy,
			'reversal_note' => $this->reversalNote,
		];
	}
}
