<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

/**
 * A remote actor that wants to follow a local account.
 *
 * The inbox is required: a Follow that cannot be answered and delivered to is
 * not a follower yet. It comes from the actor document the host fetched, not
 * from the request body of an unauthenticated caller.
 */
final readonly class FollowActor
{
	public function __construct(
		public string $localUserId,
		public string $actorId,
		public string $inboxUrl,
		public ?string $sharedInboxUrl = null,
	) {
	}
}
