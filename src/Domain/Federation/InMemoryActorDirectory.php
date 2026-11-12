<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use function array_fill_keys;
use function array_filter;
use function array_slice;
use function array_values;
use function in_array;
use function mb_stripos;
use function strcmp;
use function trim;
use function usort;

/**
 * A fixed list of local accounts, for tests, the demo and tools.
 */
final class InMemoryActorDirectory implements LocalActorDirectory, LocalActorSearch
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

	/**
	 * @param list<string>|null $allowedIds
	 *
	 * @return list<string>
	 */
	public function search(
		string $query,
		int $limit,
		int $offset,
		?array $allowedIds = null,
	): array {
		$query = trim($query);
		if ($query === '' || $limit < 1) {
			return [];
		}

		$allowed = $allowedIds === null ? null : array_fill_keys($allowedIds, true);
		$matches = array_values(array_filter(
			$this->userIds,
			static fn (string $userId): bool => ($allowed === null || isset($allowed[$userId]))
				&& mb_stripos($userId, $query) !== false,
		));

		usort($matches, static fn (string $a, string $b): int => strcmp($a, $b));

		return array_slice($matches, max(0, $offset), max(1, $limit));
	}
}
