<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use function array_key_exists;

/**
 * A fixed profile map for tests, the demo and tools.
 */
final class InMemoryLocalActorProfileProvider implements LocalActorProfileProvider
{
	/**
	 * @param array<string, LocalActorProfile> $profiles
	 */
	public function __construct(
		private array $profiles = [],
	) {
	}

	public function profile(string $userId): ?LocalActorProfile
	{
		return $this->profiles[$userId] ?? null;
	}

	public function put(LocalActorProfile $profile): void
	{
		$this->profiles[$profile->userId] = $profile;
	}

	public function remove(string $userId): void
	{
		if (array_key_exists($userId, $this->profiles)) {
			unset($this->profiles[$userId]);
		}
	}
}
