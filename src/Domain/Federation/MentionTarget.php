<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * One resolved mention: the canonical actor the handle names.
 *
 * `localId` is set for a local account and `inboxUrl` for a remote actor. A
 * local mention becomes a notification and an ActivityPub tag but not a
 * federation delivery; a remote one also gets a queued Create.
 */
final readonly class MentionTarget
{
	public function __construct(
		public string $handle,
		public string $actorId,
		public ?string $inboxUrl = null,
		public ?string $localId = null,
	) {
	}
}
