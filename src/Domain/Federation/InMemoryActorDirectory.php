<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use function in_array;

/**
 * A fixed list of local accounts, for tests, the demo and tools.
 */
final class InMemoryActorDirectory implements LocalActorDirectory
{
	/**
	 * @param list<string> $userIds
	 */
	public function __construct(
		private readonly array $userIds,
	) {
	}

	public function has(string $userId): bool
	{
		return in_array($userId, $this->userIds, true);
	}
}
