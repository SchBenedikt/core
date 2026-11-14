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
 * A validated local post waiting for moderator review.
 *
 * The draft is stored as data, not as a second Post row: nothing can list,
 * deliver or favourite it before approval. Approval turns it into exactly one
 * Post through the normal PostService path.
 */
final readonly class HeldPost
{
	public const MAX_REASON_LENGTH = 200;

	public const MAX_NOTE_LENGTH = 2000;

	/**
	 * @param array<string, mixed> $draft
	 */
	public function __construct(
		public string $id,
		public string $authorId,
		public array $draft,
		public string $reason,
		public HeldPostStatus $status,
		public ?string $postId,
		public DateTimeImmutable $createdAt,
		public ?string $reviewedBy = null,
		public ?DateTimeImmutable $reviewedAt = null,
		public ?string $reviewNote = null,
	) {
		if ($id === '' || mb_strlen($id) > 64) {
			throw new InvalidArgumentException('A held post needs a bounded id.');
		}

		if ($authorId === '') {
			throw new InvalidArgumentException('A held post needs an author.');
		}

		if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			throw new InvalidArgumentException('A held post needs a bounded reason.');
		}

		if ($reviewNote !== null && mb_strlen($reviewNote) > self::MAX_NOTE_LENGTH) {
			throw new InvalidArgumentException('A held post review note is too long.');
		}

		if (($reviewedBy === null) !== ($reviewedAt === null)) {
			throw new InvalidArgumentException('A held post review needs a moderator and a time.');
		}
	}

	public function isPending(): bool
	{
		return $this->status === HeldPostStatus::Pending;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'author_id' => $this->authorId,
			'reason' => $this->reason,
			'status' => $this->status->value,
			'post_id' => $this->postId,
			'created_at' => Time::format($this->createdAt),
			'reviewed_by' => $this->reviewedBy,
			'reviewed_at' => $this->reviewedAt === null ? null : Time::format($this->reviewedAt),
			'review_note' => $this->reviewNote,
			'draft' => $this->draft,
		];
	}
}
