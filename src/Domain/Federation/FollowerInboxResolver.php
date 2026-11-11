<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Delivers a local account's posts to the inboxes of its stored followers.
 *
 * This is the implementation the {@see InboxResolver} seam was built for: the
 * post service already asks "where does this actor's audience live?", so
 * switching the resolver on is enough to make federation fan out — no service
 * change, no host change.
 */
final class FollowerInboxResolver implements InboxResolver
{
	public function __construct(
		private readonly FollowerRepository $followers,
	) {
	}

	public function inboxesFor(string $actorId): array
	{
		return $this->followers->inboxesFor($actorId);
	}
}
