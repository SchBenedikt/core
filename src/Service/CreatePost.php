<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Visibility;

/**
 * The input of {@see PostService::create()}.
 *
 * A command object rather than eight positional parameters: it keeps the call
 * sites readable and gives validation one place to look at.
 */
final readonly class CreatePost
{
	public function __construct(
		public string $authorId,
		public string $content,
		public Visibility $visibility = Visibility::Public,
		public ?string $contentWarning = null,
		/** @var list<string> */
		public array $attachmentIds = [],
		public ?string $postId = null,
	) {
	}
}
