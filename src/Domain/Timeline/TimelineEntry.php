<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One item in the signed-in user's home timeline.
 *
 * A local post and a remote post have different storage ids, so the entry
 * carries an explicit `id` (post id for local, activity id for remote) and the
 * `object_id` being displayed. For a boost, `boosted_by` is the remote actor
 * and `object_author_id` is the author of the boosted object.
 */
final readonly class TimelineEntry
{
	public function __construct(
		public string $id,
		public string $authorId,
		public string $objectId,
		public ?string $objectAuthorId,
		public string $content,
		public ?string $contentWarning,
		public DateTimeImmutable $publishedAt,
		public ?Visibility $visibility = null,
		public ?string $boostedBy = null,
		public ?string $inReplyToId = null,
	) {
		if ($id === '' || $authorId === '' || $objectId === '') {
			throw new InvalidArgumentException('A timeline entry needs an id, an author and an object.');
		}
	}

	public static function fromPost(Post $post): self
	{
		return new self(
			id: $post->id,
			authorId: $post->authorId,
			objectId: $post->id,
			objectAuthorId: $post->authorId,
			content: $post->content,
			contentWarning: $post->contentWarning,
			publishedAt: $post->publishedAt,
			visibility: $post->visibility,
			inReplyToId: $post->replyParentRef(),
		);
	}

	public function isBoost(): bool
	{
		return $this->boostedBy !== null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'author_id' => $this->authorId,
			'object_id' => $this->objectId,
			'object_author_id' => $this->objectAuthorId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'visibility' => $this->visibility?->value,
			'published_at' => Time::format($this->publishedAt),
			'kind' => $this->isBoost() ? 'boost' : 'post',
			'boosted_by' => $this->boostedBy,
			'in_reply_to_id' => $this->inReplyToId,
		];
	}
}
