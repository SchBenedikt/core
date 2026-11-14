<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use RuntimeException;

/**
 * An edit was based on a version that is no longer the current one.
 *
 * The caller sends the revision it read; a different value means somebody
 * edited the post in between, and applying the patch would silently overwrite
 * that change.
 */
final class PostConflictException extends RuntimeException
{
	public function __construct(
		public readonly string $postId,
		public readonly int $revision,
	) {
		parent::__construct('Post ' . $postId . ' is at revision ' . $revision . '.');
	}
}
