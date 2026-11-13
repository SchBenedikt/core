<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

use function array_filter;
use function array_keys;
use function array_values;
use function strcmp;
use function uksort;
use function usort;

/**
 * Private account lists in memory, for tests and the demo.
 */
final class InMemoryAccountListRepository implements AccountListRepository
{
	/**
	 * @var array<string, AccountList>
	 */
	private array $lists = [];

	/**
	 * @var array<string, array<string, DateTimeImmutable>>
	 */
	private array $members = [];

	public function insert(AccountList $list): void
	{
		if (isset($this->lists[$list->id])) {
			throw new DatabaseException('The list id is already stored.');
		}

		$this->lists[$list->id] = $list;
		$this->members[$list->id] = [];
	}

	public function find(string $ownerId, string $id): ?AccountList
	{
		$list = $this->lists[$id] ?? null;

		return $list !== null && $list->userId === $ownerId ? $list : null;
	}

	public function lists(string $ownerId): array
	{
		$lists = array_values(array_filter(
			$this->lists,
			static fn (AccountList $list): bool => $list->userId === $ownerId,
		));

		usort($lists, static function (AccountList $a, AccountList $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $lists;
	}

	public function update(AccountList $list): bool
	{
		$stored = $this->lists[$list->id] ?? null;
		if ($stored === null || $stored->userId !== $list->userId) {
			return false;
		}

		$this->lists[$list->id] = $list;

		return true;
	}

	public function delete(string $ownerId, string $id): bool
	{
		if ($this->find($ownerId, $id) === null) {
			return false;
		}

		unset($this->lists[$id], $this->members[$id]);

		return true;
	}

	public function addMembers(
		string $ownerId,
		string $listId,
		array $accountIds,
		DateTimeImmutable $addedAt,
	): int {
		if ($this->find($ownerId, $listId) === null) {
			return 0;
		}

		$added = 0;
		foreach ($accountIds as $accountId) {
			if (isset($this->members[$listId][$accountId])) {
				continue;
			}

			$this->members[$listId][$accountId] = $addedAt;
			$added++;
		}

		return $added;
	}

	public function removeMembers(string $ownerId, string $listId, array $accountIds): int
	{
		if ($this->find($ownerId, $listId) === null) {
			return 0;
		}

		$removed = 0;
		foreach ($accountIds as $accountId) {
			if (!isset($this->members[$listId][$accountId])) {
				continue;
			}

			unset($this->members[$listId][$accountId]);
			$removed++;
		}

		return $removed;
	}

	public function memberIds(string $ownerId, string $listId): array
	{
		if ($this->find($ownerId, $listId) === null) {
			return [];
		}

		$members = $this->members[$listId] ?? [];
		uksort($members, static function (string $a, string $b) use ($members): int {
			$byTime = $members[$b] <=> $members[$a];

			return $byTime !== 0 ? $byTime : strcmp($b, $a);
		});

		return array_keys($members);
	}

	public function exclusiveActorIds(string $ownerId): array
	{
		$ids = [];
		foreach ($this->lists($ownerId) as $list) {
			if (!$list->exclusive) {
				continue;
			}

			foreach ($this->memberIds($ownerId, $list->id) as $accountId) {
				$ids[$accountId] = true;
			}
		}

		return array_keys($ids);
	}
}
