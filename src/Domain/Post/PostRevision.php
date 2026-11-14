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
 * One superseded version of a local post.
 *
 * A revision is written when an edit replaces the live row, and it describes
 * the version that is being replaced: its number is the post revision it
 * belonged to. Snapshots are immutable and never rewritten, so the edit
 * history stays readable even after later edits or a deletion.
 */
final readonly class PostRevision
{
	public function __construct(
		public string $postId,
		public int $revision,
		public string $content,
		public ?string $contentWarning,
		public Visibility $visibility,
		/** @var list<string> */
		public array $attachmentIds,
		public DateTimeImmutable $editedAt,
	) {
		if ($postId === '') {
			throw new InvalidArgumentException('A revision needs a post id.');
		}

		if ($revision < 1) {
			throw new InvalidArgumentException('A revision number starts at one.');
		}
	}

	/**
	 * The shape the API returns.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'post_id' => $this->postId,
			'revision' => $this->revision,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'visibility' => $this->visibility->value,
			'attachment_ids' => $this->attachmentIds,
			'edited_at' => Time::format($this->editedAt),
		];
	}
}
