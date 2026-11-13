<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PDOException;
use Throwable;

use function array_map;
use function array_values;
use function implode;

/**
 * Private account lists in SQL.
 *
 * The list and membership tables are separate. Every statement joins the
 * owning list for member reads, so a guessed list id cannot expose another
 * account's membership. Fixed-width timestamps keep the cursor portable.
 */
final class PdoAccountListRepository implements AccountListRepository
{
	private const COLUMNS = 'id, user_id, title, replies_policy, exclusive, created_at, updated_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(AccountList $list): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . AccountListSchema::TABLE
				. ' (' . self::COLUMNS . ')'
				. ' VALUES (:id, :user_id, :title, :replies_policy, :exclusive, :created_at, :updated_at)',
				[
					'id' => $list->id,
					'user_id' => $list->userId,
					'title' => $list->title,
					'replies_policy' => $list->repliesPolicy->value,
					'exclusive' => $list->exclusive ? 1 : 0,
					'created_at' => Time::format($list->createdAt),
					'updated_at' => Time::format($list->updatedAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The list could not be stored.', 0, $exception);
		}
	}

	public function find(string $ownerId, string $id): ?AccountList
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AccountListSchema::TABLE
			. ' WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $ownerId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function lists(string $ownerId): array
	{
		return array_map(
			fn (array $row): AccountList => $this->hydrate($row),
			$this->db->fetchAll(
				'SELECT ' . self::COLUMNS . ' FROM ' . AccountListSchema::TABLE
				. ' WHERE user_id = :user_id ORDER BY created_at DESC, id DESC',
				['user_id' => $ownerId],
			),
		);
	}

	public function update(AccountList $list): bool
	{
		try {
			return $this->db->execute(
				'UPDATE ' . AccountListSchema::TABLE
				. ' SET title = :title, replies_policy = :replies_policy, exclusive = :exclusive,'
				. ' updated_at = :updated_at'
				. ' WHERE id = :id AND user_id = :user_id',
				[
					'title' => $list->title,
					'replies_policy' => $list->repliesPolicy->value,
					'exclusive' => $list->exclusive ? 1 : 0,
					'updated_at' => Time::format($list->updatedAt),
					'id' => $list->id,
					'user_id' => $list->userId,
				],
			) > 0;
		} catch (PDOException $exception) {
			throw new DatabaseException('The list could not be updated.', 0, $exception);
		}
	}

	public function delete(string $ownerId, string $id): bool
	{
		return $this->db->transaction(function (Connection $db) use ($ownerId, $id): bool {
			// Delete the owned list first. A guessed id belonging to another
			// account must never remove that account's membership rows.
			$deleted = $db->execute(
				'DELETE FROM ' . AccountListSchema::TABLE
				. ' WHERE id = :id AND user_id = :user_id',
				['id' => $id, 'user_id' => $ownerId],
			);
			if ($deleted === 0) {
				return false;
			}

			$db->execute(
				'DELETE FROM ' . AccountListSchema::MEMBER_TABLE . ' WHERE list_id = :list_id',
				['list_id' => $id],
			);

			return true;
		});
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

		return $this->db->transaction(function (Connection $db) use ($ownerId, $listId, $accountIds, $addedAt): int {
			$added = 0;
			foreach (array_values($accountIds) as $accountId) {
				try {
					$db->execute(
						'INSERT INTO ' . AccountListSchema::MEMBER_TABLE
						. ' (list_id, account_id, created_at) VALUES (:list_id, :account_id, :created_at)',
						[
							'list_id' => $listId,
							'account_id' => $accountId,
							'created_at' => Time::format($addedAt),
						],
					);
					$added++;
				} catch (Throwable $exception) {
					// The composite key is the idempotency boundary. Only a
					// duplicate is a no-op; a missing table or a foreign key
					// failure must still surface.
					if (!$this->memberExists($ownerId, $listId, $accountId)) {
						throw new DatabaseException('The list member could not be stored.', 0, $exception);
					}
				}
			}

			return $added;
		});
	}

	public function removeMembers(string $ownerId, string $listId, array $accountIds): int
	{
		if ($accountIds === [] || $this->find($ownerId, $listId) === null) {
			return 0;
		}

		$placeholders = [];
		$parameters = ['list_id' => $listId];
		foreach (array_values($accountIds) as $index => $accountId) {
			$name = 'account_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $accountId;
		}

		return $this->db->execute(
			'DELETE FROM ' . AccountListSchema::MEMBER_TABLE
			. ' WHERE list_id = :list_id AND account_id IN (' . implode(', ', $placeholders) . ')',
			$parameters,
		);
	}

	public function memberIds(string $ownerId, string $listId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT m.account_id FROM ' . AccountListSchema::MEMBER_TABLE . ' m'
			. ' INNER JOIN ' . AccountListSchema::TABLE . ' l ON l.id = m.list_id'
			. ' WHERE l.id = :list_id AND l.user_id = :user_id'
			. ' ORDER BY m.created_at DESC, m.account_id DESC',
			['list_id' => $listId, 'user_id' => $ownerId],
		);

		return array_map(static fn (array $row): string => (string) $row['account_id'], $rows);
	}

	public function exclusiveActorIds(string $ownerId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT m.account_id FROM ' . AccountListSchema::MEMBER_TABLE . ' m'
			. ' INNER JOIN ' . AccountListSchema::TABLE . ' l ON l.id = m.list_id'
			. ' WHERE l.user_id = :user_id AND l.exclusive = 1'
			. ' ORDER BY m.created_at DESC, m.account_id DESC',
			['user_id' => $ownerId],
		);

		return array_map(static fn (array $row): string => (string) $row['account_id'], $rows);
	}

	private function memberExists(string $ownerId, string $listId, string $accountId): bool
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . AccountListSchema::MEMBER_TABLE . ' m'
			. ' INNER JOIN ' . AccountListSchema::TABLE . ' l ON l.id = m.list_id'
			. ' WHERE m.list_id = :list_id AND m.account_id = :account_id AND l.user_id = :user_id',
			['list_id' => $listId, 'account_id' => $accountId, 'user_id' => $ownerId],
		) > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): AccountList
	{
		return new AccountList(
			id: (string) $row['id'],
			userId: (string) $row['user_id'],
			title: (string) $row['title'],
			repliesPolicy: ListRepliesPolicy::from((string) $row['replies_policy']),
			exclusive: (int) $row['exclusive'] === 1,
			createdAt: Time::parse((string) $row['created_at']),
			updatedAt: Time::parse((string) $row['updated_at']),
		);
	}
}
