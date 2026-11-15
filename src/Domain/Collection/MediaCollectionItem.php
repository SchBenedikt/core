<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Collection;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One ordered reference from a collection to a post. The post is not copied
 * and its visibility is checked again on every read.
 */
final readonly class MediaCollectionItem
{
	public function __construct(
		public string $collectionId,
		public string $postId,
		public int $position,
		public DateTimeImmutable $addedAt,
	) {
		if ($collectionId === '' || $postId === '') {
			throw new InvalidArgumentException('A collection item needs a collection and a post.');
		}

		if ($position < 1) {
			throw new InvalidArgumentException('A collection item position starts at one.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'collection_id' => $this->collectionId,
			'post_id' => $this->postId,
			'position' => $this->position,
			'added_at' => Time::format($this->addedAt),
		];
	}
}
