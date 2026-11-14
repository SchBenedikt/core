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
 * repository contract (and the tests) free of hidden mutation. `revision`
 * starts at one and counts edits, so a caller can detect that somebody else
 * changed the post before its own patch was applied.
 */
final readonly class Post
{
	public const MAX_ATTACHMENTS = 4;

	public function __construct(
		public string $id,
		public string $authorId,
		public string $content,
		public Visibility $visibility,
		public DateTimeImmutable $publishedAt,
		public ?string $contentWarning = null,
		public ?DateTimeImmutable $deletedAt = null,
		/** @var list<string> */
		public array $attachmentIds = [],
		public ?Poll $poll = null,
		/** @var list<string> local account ids for a direct post */
		public array $recipientIds = [],
		public ?string $inReplyToId = null,
		public ?DateTimeImmutable $editedAt = null,
		public int $revision = 1,
	) {
		if ($revision < 1) {
			throw new InvalidArgumentException('A post revision starts at one.');
		}

		if ($editedAt !== null && $editedAt < $publishedAt) {
			throw new InvalidArgumentException('A post cannot have been edited before it was published.');
		}

		if ($id === '') {
			throw new InvalidArgumentException('A post needs an id.');
		}

		if ($authorId === '') {
			throw new InvalidArgumentException('A post needs an author.');
		}

		if (count($attachmentIds) > self::MAX_ATTACHMENTS) {
			throw new InvalidArgumentException('A post has too many attachments.');
		}

		if (array_values($attachmentIds) !== $attachmentIds) {
			throw new InvalidArgumentException('Post attachment ids must be a list.');
		}

		$seen = [];
		foreach ($attachmentIds as $attachmentId) {
			if (!is_string($attachmentId) || $attachmentId === '') {
				throw new InvalidArgumentException('A post attachment needs an id.');
			}

			if (isset($seen[$attachmentId])) {
				throw new InvalidArgumentException('A post cannot repeat an attachment.');
			}

			$seen[$attachmentId] = true;
		}

		if (array_values($recipientIds) !== $recipientIds) {
			throw new InvalidArgumentException('Post recipient ids must be a list.');
		}

		$seenRecipients = [];
		foreach ($recipientIds as $recipientId) {
			if (!is_string($recipientId) || $recipientId === '') {
				throw new InvalidArgumentException('A post recipient needs an id.');
			}

			if (isset($seenRecipients[$recipientId])) {
				throw new InvalidArgumentException('A post cannot repeat a recipient.');
			}

			$seenRecipients[$recipientId] = true;
		}

		if ($inReplyToId !== null && trim($inReplyToId) === '') {
			throw new InvalidArgumentException('A reply target needs an id.');
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
			'edited_at' => $this->editedAt === null ? null : Time::format($this->editedAt),
			'revision' => $this->revision,
			'deleted_at' => $this->deletedAt === null ? null : Time::format($this->deletedAt),
			'poll' => $this->poll?->toArray(),
			'in_reply_to_id' => $this->inReplyToId,
			'recipient_ids' => $this->recipientIds,
		];
	}
}
