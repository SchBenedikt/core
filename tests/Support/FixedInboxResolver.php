<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Federation\InboxResolver;

/**
 * Pretends the follow graph already exists.
 */
final class FixedInboxResolver implements InboxResolver
{
	/**
	 * @param list<string> $inboxes
	 */
	public function __construct(
		private readonly array $inboxes,
	) {
	}

	public function inboxesFor(string $actorId): array
	{
		return $this->inboxes;
	}
}
