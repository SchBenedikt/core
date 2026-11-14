<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Notification;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function hash;
use function mb_strlen;
use function substr;

/**
 * One sender decision for one recipient.
 *
 * The id is derived from (recipient, actor), so accepting, dismissing and
 * holding the same sender address one row. A repeated lifecycle call keeps the
 * first decision unless the API explicitly changes it, and no follow, block or
 * post row is touched by any transition.
 */
final readonly class NotificationSender
{
	public const MAX_ID_LENGTH = 80;

	public const MAX_ACTOR_LENGTH = 512;

	public function __construct(
		public string $id,
		public string $recipientId,
		public string $actorId,
		public NotificationSenderStatus $status,
		public NotificationSenderCategory $category,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $reviewedAt = null,
		public ?string $note = null,
	) {
		if ($id === '' || $recipientId === '' || $actorId === '') {
			throw new InvalidArgumentException('A sender request needs an id, a recipient and an actor.');
		}

		if (mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			throw new InvalidArgumentException('The sender actor id is too long.');
		}
	}

	public static function idFor(string $recipientId, string $actorId): string
	{
		return 'notification-sender-' . substr(hash('sha256', $recipientId . "\0" . $actorId), 0, 32);
	}

	public function withStatus(
		NotificationSenderStatus $status,
		DateTimeImmutable $reviewedAt,
		?string $note = null,
	): self {
		return new self(
			id: $this->id,
			recipientId: $this->recipientId,
			actorId: $this->actorId,
			status: $status,
			category: $this->category,
			createdAt: $this->createdAt,
			reviewedAt: $reviewedAt,
			note: $note,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'actor_id' => $this->actorId,
			'status' => $this->status->value,
			'category' => $this->category->value,
			'created_at' => Time::format($this->createdAt),
			'reviewed_at' => $this->reviewedAt === null ? null : Time::format($this->reviewedAt),
			'note' => $this->note,
		];
	}
}
