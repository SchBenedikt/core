<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\FollowedActor;

/**
 * The outcome of a local Follow request.
 *
 * `created` is false when the actor was already followed; the handler answers
 * 200 instead of 201 and no second Follow is sent.
 */
final readonly class FollowingResult
{
	public function __construct(
		public FollowedActor $followed,
		public bool $created,
	) {
	}
}
