<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\Follower;

/**
 * The outcome of a Follow request.
 *
 * `created` is false when the actor was already a follower and only its inbox
 * was refreshed; the handler uses it to answer 201 or 200 without a second
 * lookup.
 */
final readonly class FollowResult
{
	public function __construct(
		public Follower $follower,
		public bool $created,
	) {
	}
}
