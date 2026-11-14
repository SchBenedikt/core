<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Visibility;

/**
 * The input of {@see PostService::edit()}.
 *
 * Every field is optional because an edit is a patch: `null` means "leave this
 * alone". Clearing the content warning is therefore its own flag, not the
 * absence of a value — otherwise a caller could never remove a warning.
 */
final readonly class UpdatePost
{
	public function __construct(
		public ?string $content = null,
		public ?string $contentWarning = null,
		public bool $contentWarningProvided = false,
		/** @var list<string>|null */
		public ?array $attachmentIds = null,
		public ?Visibility $visibility = null,
		/**
		 * The revision the caller read. A mismatch is refused instead of
		 * overwriting an edit that happened in between.
		 */
		public ?int $expectedRevision = null,
	) {
	}

	/**
	 * Whether the command changes anything at all.
	 */
	public function isEmpty(): bool
	{
		return $this->content === null
			&& !$this->contentWarningProvided
			&& $this->attachmentIds === null
			&& $this->visibility === null;
	}
}
