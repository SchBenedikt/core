<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PDOException;

use function array_fill_keys;
use function array_values;
use function implode;

/**
 * Per-user post favourites in SQL.
 *
 * The composite primary key is the authority on idempotence. Timestamps use
 * the canonical fixed-width form used by the rest of the core.
 */
final class PdoFavouriteRepository implements FavouriteRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Favourite $favourite): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . FavouriteSchema::TABLE
				. ' (user_id, post_id, created_at)'
				. ' VALUES (:user_id, :post_id, :created_at)',
				[
					'user_id' => $favourite->userId,
					'post_id' => $favourite->postId,
					'created_at' => Time::format($favourite->createdAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The favourite could not be stored.', 0, $exception);
		}
	}

	public function remove(string $userId, string $postId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . FavouriteSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		) > 0;
	}

	public function exists(string $userId, string $postId): bool
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FavouriteSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		) > 0;
	}

	public function countForPost(string $postId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . FavouriteSchema::TABLE . ' WHERE post_id = :post_id',
			['post_id' => $postId],
		);
	}

	public function countsForPosts(array $postIds): array
	{
		$counts = array_fill_keys(array_values($postIds), 0);
		if ($postIds === []) {
			return $counts;
		}

		[$placeholders, $parameters] = $this->placeholders($postIds);
		$rows = $this->db->fetchAll(
			'SELECT post_id, COUNT(*) AS total FROM ' . FavouriteSchema::TABLE
			. ' WHERE post_id IN (' . implode(', ', $placeholders) . ')'
			. ' GROUP BY post_id',
			$parameters,
		);

		foreach ($rows as $row) {
			$postId = (string) $row['post_id'];
			if (isset($counts[$postId])) {
				$counts[$postId] = (int) $row['total'];
			}
		}

		return $counts;
	}

	public function existsForPosts(string $userId, array $postIds): array
	{
		$exists = array_fill_keys(array_values($postIds), false);
		if ($postIds === []) {
			return $exists;
		}

		[$placeholders, $parameters] = $this->placeholders($postIds);
		$parameters['user_id'] = $userId;
		$rows = $this->db->fetchAll(
			'SELECT post_id FROM ' . FavouriteSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id IN (' . implode(', ', $placeholders) . ')',
			$parameters,
		);

		foreach ($rows as $row) {
			$postId = (string) $row['post_id'];
			if (isset($exists[$postId])) {
				$exists[$postId] = true;
			}
		}

		return $exists;
	}

	/**
	 * @param list<string> $postIds
	 *
	 * @return array{0: list<string>, 1: array<string, string>}
	 */
	private function placeholders(array $postIds): array
	{
		$placeholders = [];
		$parameters = [];
		foreach (array_values($postIds) as $index => $postId) {
			$name = 'post_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $postId;
		}

		return [$placeholders, $parameters];
	}
}
