<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * The default policy: this core instance has no block list, so nothing is
 * blocked. A host with block management supplies its own policy instead.
 */
final class NoBlockedActors implements BlockedActorPolicy
{
	public function isBlocked(string $actorId): bool
	{
		return false;
	}
}
