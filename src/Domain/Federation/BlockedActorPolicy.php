<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Host-provided decision whether an actor is blocked at the instance level.
 *
 * The core has no block management yet. Keeping the decision behind this port
 * lets a host reject all inbound content from an actor without teaching the
 * inbox service about a particular block table.
 */
interface BlockedActorPolicy
{
	public function isBlocked(string $actorId): bool;
}
