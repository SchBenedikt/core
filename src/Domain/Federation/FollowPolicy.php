<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Host-provided decision whether an inbound Follow may create a follower edge.
 *
 * The signature proves who the actor is; this policy decides whether this
 * local account accepts that actor. It runs after signature verification and
 * before the follower repository changes, so a refusal leaves no edge and
 * releases the activity claim for a later retry.
 */
interface FollowPolicy
{
	public function accepts(RemoteActor $actor, string $localUserId): bool;
}
