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
 * URL for a remote follower. `postId` is set only for a favourite, because a
 * follow notification has no status to show.
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
	) {
		if ($id === '' || $recipientId === '' || $actorId === '') {
			throw new InvalidArgumentException('A notification needs an id, a recipient and an actor.');
		}

		if ($type === NotificationType::Favourite && $postId === null) {
			throw new InvalidArgumentException('A favourite notification needs a post.');
		}

		if ($type === NotificationType::Follow && $postId !== null) {
			throw new InvalidArgumentException('A follow notification cannot point at a post.');
		}
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
		];
	}
}
