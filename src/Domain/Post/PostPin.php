<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One profile pin. Ordering is explicit and bounded so a profile cannot turn
 * its featured collection into a second timeline.
 */
final readonly class PostPin
{
	public const MAX_PINS = 5;

	public function __construct(
		public string $ownerId,
		public string $postId,
		public int $position,
		public DateTimeImmutable $pinnedAt,
	) {
		if ($ownerId === '' || $postId === '') {
			throw new InvalidArgumentException('A pin needs an owner and a post.');
		}

		if ($position < 1 || $position > self::MAX_PINS) {
			throw new InvalidArgumentException('A pin position must be between one and five.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'post_id' => $this->postId,
			'position' => $this->position,
			'pinned_at' => Time::format($this->pinnedAt),
		];
	}
}
