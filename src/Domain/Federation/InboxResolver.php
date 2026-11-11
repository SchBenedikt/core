<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Answers where an actor's posts have to be delivered.
 *
 * Today the only implementation is empty — the follow graph is not part of
 * this milestone. The seam is here so that adding it changes no service: the
 * follower-backed implementation drops in and delivery starts to fan out.
 */
interface InboxResolver
{
	/**
	 * @return list<string> absolute inbox URLs
	 */
	public function inboxesFor(string $actorId): array;
}
