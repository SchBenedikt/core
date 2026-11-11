<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use RuntimeException;

/**
 * A post was asked for that does not exist, or is deleted.
 */
final class PostNotFoundException extends RuntimeException
{
	public function __construct(
		public readonly string $postId,
	) {
		parent::__construct('Post not found: ' . $postId);
	}
}
