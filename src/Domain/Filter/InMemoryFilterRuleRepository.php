<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use AlohaSocial\Core\Platform\Db\DatabaseException;

use function array_filter;
use function array_values;
use function usort;

/**
 * In-memory keyword filters with the same owner scoping as the PDO store.
 */
final class InMemoryFilterRuleRepository implements FilterRuleRepository
{
	/**
	 * @var array<string, FilterRule>
	 */
	private array $rows = [];

	public function insert(FilterRule $rule): void
	{
		if (isset($this->rows[$rule->id])) {
			throw new DatabaseException('The filter id is taken.');
		}

		$this->rows[$rule->id] = $rule;
	}

	public function update(FilterRule $rule): void
	{
		if (!isset($this->rows[$rule->id])) {
			return;
		}

		$this->rows[$rule->id] = $rule;
	}

	public function find(string $id): ?FilterRule
	{
		return $this->rows[$id] ?? null;
	}

	public function delete(string $id, string $userId): bool
	{
		$rule = $this->rows[$id] ?? null;
		if ($rule === null || $rule->userId !== $userId) {
			return false;
		}

		unset($this->rows[$id]);

		return true;
	}

	public function forUser(string $userId): array
	{
		$rows = array_values(array_filter(
			$this->rows,
			static fn (FilterRule $rule): bool => $rule->userId === $userId,
		));

		usort($rows, static fn (FilterRule $left, FilterRule $right): int => $right->createdAt <=> $left->createdAt
			?: $right->id <=> $left->id);

		return $rows;
	}

	public function countForUser(string $userId): int
	{
		$count = 0;
		foreach ($this->rows as $rule) {
			if ($rule->userId === $userId) {
				$count++;
			}
		}

		return $count;
	}
}
