<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

/**
 * A local account blocking one remote actor.
 *
 * The actor id is the identity. A block never stores an inbox: it is a local
 * visibility decision, not a delivery target.
 */
final readonly class BlockActor
{
	public function __construct(
		public string $localUserId,
		public string $actorId,
	) {
	}
}
