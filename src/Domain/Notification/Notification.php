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

/**
 * One event addressed to a local account.
 *
 * `actorId` is a local user id for local interactions and an absolute actor
 * URL for a remote follower. `postId` is set for every event about a status
 * and absent only for a follow. `readAt` is null while the event
 * is unread and is set by a dismiss or clear action; the row itself stays for
 * the host's retention policy.
 */
final readonly class Notification
{
	public function __construct(
		public string $id,
		public string $recipientId,
		public string $actorId,
		public NotificationType $type,
		public ?string $postId,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $readAt = null,
	) {
		if ($id === '' || $recipientId === '' || $actorId === '') {
			throw new InvalidArgumentException('A notification needs an id, a recipient and an actor.');
		}

		if ($type === NotificationType::Follow && $postId !== null) {
			throw new InvalidArgumentException('A follow notification cannot point at a post.');
		}

		if ($type !== NotificationType::Follow && $postId === null) {
			throw new InvalidArgumentException('This notification needs a post.');
		}
	}

	public function isRead(): bool
	{
		return $this->readAt !== null;
	}

	/**
	 * Returns the same event with a read marker. Dismiss and clear are the only
	 * callers; a repeat keeps the first marker so the operation stays
	 * idempotent.
	 */
	public function withReadAt(DateTimeImmutable $readAt): self
	{
		return new self(
			id: $this->id,
			recipientId: $this->recipientId,
			actorId: $this->actorId,
			type: $this->type,
			postId: $this->postId,
			createdAt: $this->createdAt,
			readAt: $readAt,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'recipient_id' => $this->recipientId,
			'actor_id' => $this->actorId,
			'type' => $this->type->value,
			'post_id' => $this->postId,
			'created_at' => Time::format($this->createdAt),
			'read_at' => $this->readAt === null ? null : Time::format($this->readAt),
		];
	}
}
