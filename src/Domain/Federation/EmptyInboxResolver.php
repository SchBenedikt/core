<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * An instance without a follow graph yet: nothing to deliver to.
 *
 * It makes the absence explicit instead of hiding it behind a null check in
 * the post service.
 */
final class EmptyInboxResolver implements InboxResolver
{
	public function inboxesFor(string $actorId): array
	{
		return [];
	}
}
