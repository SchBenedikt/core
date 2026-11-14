<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

/**
 * Storage of per-reader keyword filters.
 *
 * Reads are owner-scoped by the caller; a repository lookup by id is only used
 * after the service has checked the owner, because an id must not become a way
 * to read another reader's rules.
 */
interface FilterRuleRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is taken
	 */
	public function insert(FilterRule $rule): void;

	/**
	 * Replaces a rule and its keyword list.
	 */
	public function update(FilterRule $rule): void;

	public function find(string $id): ?FilterRule;

	/**
	 * Deletes only when the row belongs to the owner.
	 */
	public function delete(string $id, string $userId): bool;

	/**
	 * Every rule of the owner, newest first with the id as tie-breaker.
	 *
	 * Expired rows are included: the API shows them so a reader can remove or
	 * extend one, and the service filters them out when matching.
	 *
	 * @return list<FilterRule>
	 */
	public function forUser(string $userId): array;

	public function countForUser(string $userId): int;
}
