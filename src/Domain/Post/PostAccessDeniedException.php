<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use RuntimeException;

/**
 * Somebody tried to change a post they do not own.
 */
final class PostAccessDeniedException extends RuntimeException
{
	public function __construct(
		public readonly string $postId,
		public readonly string $actorId,
	) {
		parent::__construct('Account ' . $actorId . ' may not change post ' . $postId . '.');
	}
}
