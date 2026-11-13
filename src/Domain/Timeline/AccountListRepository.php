<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use DateTimeImmutable;

/**
 * Storage of private account lists and their membership.
 *
 * A list belongs to one local account. Every read and write is scoped to that
 * owner, so another account cannot discover, change or use a list by guessing
 * its id. Membership is idempotent by (list, account); re-adding an account
 * keeps its original position.
 */
interface AccountListRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is taken
	 */
	public function insert(AccountList $list): void;

	public function find(string $ownerId, string $id): ?AccountList;

	/**
	 * The owner's lists, newest first with the id as the tie-break.
	 *
	 * @return list<AccountList>
	 */
	public function lists(string $ownerId): array;

	/**
	 * @return bool whether an owned row was updated
	 */
	public function update(AccountList $list): bool;

	/**
	 * Deletes the list and its membership rows.
	 *
	 * @return bool whether an owned list was removed
	 */
	public function delete(string $ownerId, string $id): bool;

	/**
	 * Adds accounts to an owned list. Existing pairs are left untouched.
	 *
	 * @param list<string> $accountIds
	 *
	 * @return int number of newly added pairs
	 */
	public function addMembers(
		string $ownerId,
		string $listId,
		array $accountIds,
		DateTimeImmutable $addedAt,
	): int;

	/**
	 * Removes accounts from an owned list. Absent pairs are a no-op.
	 *
	 * @param list<string> $accountIds
	 *
	 * @return int number of removed pairs
	 */
	public function removeMembers(string $ownerId, string $listId, array $accountIds): int;

	/**
	 * The owner's list members, newest first with the account id as the
	 * tie-break.
	 *
	 * @return list<string>
	 */
	public function memberIds(string $ownerId, string $listId): array;

	/**
	 * Every account in one of the owner's exclusive lists.
	 *
	 * The home timeline uses this to suppress local posts from list members.
	 *
	 * @return list<string>
	 */
	public function exclusiveActorIds(string $ownerId): array;
}
