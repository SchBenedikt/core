<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One persisted object in a remote conversation.
 *
 * A thread node is not a delivered activity: it may be an ancestor or a reply
 * fetched from the object's origin, so it has no activity id or kind. The
 * object id is the idempotence key and the link to the remote post that
 * started the context request.
 */
final readonly class RemoteThreadPost
{
	public const MAX_ID_LENGTH = 512;

	public const MAX_AUTHOR_LENGTH = 255;

	public const MAX_CONTENT_LENGTH = 20_000;

	public const MAX_WARNING_LENGTH = 255;

	public function __construct(
		public string $objectId,
		public string $authorId,
		public string $content,
		public DateTimeImmutable $publishedAt,
		public DateTimeImmutable $fetchedAt,
		public ?string $contentWarning = null,
		public ?string $inReplyToId = null,
	) {
		if ($objectId === '' || $authorId === '' || $content === '') {
			throw new InvalidArgumentException('A thread post needs an object, an author and content.');
		}

		if (mb_strlen($objectId) > self::MAX_ID_LENGTH
			|| mb_strlen($authorId) > self::MAX_AUTHOR_LENGTH
			|| ($inReplyToId !== null && mb_strlen($inReplyToId) > self::MAX_ID_LENGTH)
		) {
			throw new InvalidArgumentException('A thread post id or author is too long.');
		}

		if (mb_strlen($content) > self::MAX_CONTENT_LENGTH
			|| ($contentWarning !== null && mb_strlen($contentWarning) > self::MAX_WARNING_LENGTH)
		) {
			throw new InvalidArgumentException('A thread post content is too long.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'object_id' => $this->objectId,
			'author_id' => $this->authorId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'published_at' => Time::format($this->publishedAt),
			'in_reply_to_id' => $this->inReplyToId,
		];
	}
}
