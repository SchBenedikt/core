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
 * A post written on this instance.
 *
 * Immutable: an edit or a deletion produces a new instance, which keeps the
 * repository contract (and the tests) free of hidden mutation.
 */
final readonly class Post
{
	public function __construct(
		public string $id,
		public string $authorId,
		public string $content,
		public Visibility $visibility,
		public DateTimeImmutable $publishedAt,
		public ?string $contentWarning = null,
		public ?DateTimeImmutable $deletedAt = null,
	) {
		if ($id === '') {
			throw new InvalidArgumentException('A post needs an id.');
		}

		if ($authorId === '') {
			throw new InvalidArgumentException('A post needs an author.');
		}
	}

	public function isDeleted(): bool
	{
		return $this->deletedAt !== null;
	}

	/**
	 * The shape the API returns.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'author_id' => $this->authorId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'visibility' => $this->visibility->value,
			'published_at' => Time::format($this->publishedAt),
			'deleted_at' => $this->deletedAt === null ? null : Time::format($this->deletedAt),
		];
	}
}
