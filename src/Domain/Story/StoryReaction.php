<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Story;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;

/**
 * A private reaction to a story. It is not a post and is never federated as
 * one; only a story viewer can add it and only viewers/the owner can read it.
 */
final readonly class StoryReaction
{
	public const MAX_EMOJI_LENGTH = 64;

	public function __construct(
		public string $storyId,
		public string $userId,
		public string $emoji,
		public DateTimeImmutable $createdAt,
	) {
		if ($storyId === '' || $userId === '') {
			throw new InvalidArgumentException('A story reaction needs a story and a user.');
		}

		if ($emoji === '' || mb_strlen($emoji) > self::MAX_EMOJI_LENGTH) {
			throw new InvalidArgumentException('A story reaction emoji is invalid.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'story_id' => $this->storyId,
			'user_id' => $this->userId,
			'emoji' => $this->emoji,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
