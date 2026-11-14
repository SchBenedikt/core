<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Moderation\HeldPost;
use RuntimeException;

/**
 * Thrown when a post was validated but a hold policy sent it to review.
 *
 * The request did not fail: the caller gets the held-post id and the stable
 * reason, and no Post row or delivery job exists yet.
 */
final class PostHeldException extends RuntimeException
{
	public function __construct(
		public readonly HeldPost $heldPost,
	) {
		parent::__construct('The post is waiting for moderator review.');
	}
}
