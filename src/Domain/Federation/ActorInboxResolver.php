<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Resolves an actor URL to the inbox that receives actor-specific activities.
 *
 * A block is addressed to one remote actor, so the follow graph's stored
 * inboxes are not enough: the actor may never have followed a local account.
 * The host supplies this capability; an instance without it keeps blocks
 * local and sends no ActivityPub `Block`.
 */
interface ActorInboxResolver
{
    public function inboxFor(string $actorId): ?string;
}
