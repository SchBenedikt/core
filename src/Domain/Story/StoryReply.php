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
 * A private reply attached to a story. It stays inside the story's audience
 * and is deliberately not turned into a public post.
 */
final readonly class StoryReply
{
	public const MAX_CONTENT_LENGTH = 2000;

	public function __construct(
		public string $id,
		public string $storyId,
		public string $authorId,
		public string $content,
		public DateTimeImmutable $createdAt,
	) {
		if ($id === '' || $storyId === '' || $authorId === '') {
			throw new InvalidArgumentException('A story reply needs an id, a story and an author.');
		}

		if ($content === '' || mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
			throw new InvalidArgumentException('A story reply content is invalid.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'story_id' => $this->storyId,
			'author_id' => $this->authorId,
			'content' => $this->content,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
