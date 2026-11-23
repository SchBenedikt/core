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
use DateTimeImmutable;
use PDOException;

use function array_fill_keys;
use function array_map;
use function array_values;
use function implode;

/**
 * Local boosts in SQL.
 *
 * The composite unique index is the authority on idempotence; timestamps use
 * the canonical fixed-width form so ordering matches the in-memory adapter.
 */
final class PdoBoostRepository implements BoostRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Boost $boost): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . BoostSchema::TABLE
				. ' (id, user_id, post_id, created_at)'
				. ' VALUES (:id, :user_id, :post_id, :created_at)',
				[
					'id' => $boost->id,
					'user_id' => $boost->userId,
					'post_id' => $boost->postId,
					'created_at' => Time::format($boost->createdAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The boost could not be stored.', 0, $exception);
		}
	}

	public function find(string $id): ?Boost
	{
		$row = $this->db->fetchOne(
			'SELECT id, user_id, post_id, created_at FROM ' . BoostSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findByUserAndPost(string $userId, string $postId): ?Boost
	{
		$row = $this->db->fetchOne(
			'SELECT id, user_id, post_id, created_at FROM ' . BoostSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function remove(string $userId, string $postId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . BoostSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		) > 0;
	}

	public function exists(string $userId, string $postId): bool
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . BoostSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		) > 0;
	}

	public function countForPost(string $postId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . BoostSchema::TABLE . ' WHERE post_id = :post_id',
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
			'SELECT post_id, COUNT(*) AS total FROM ' . BoostSchema::TABLE
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
			'SELECT post_id FROM ' . BoostSchema::TABLE
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

	public function timelineForUsers(array $userIds, int $limit, DateTimeImmutable|TimelineCursor|null $before = null): array
	{
		if ($userIds === []) {
			return [];
		}

		[$placeholders, $parameters] = $this->placeholders($userIds);
		$sql = 'SELECT id, user_id, post_id, created_at FROM ' . BoostSchema::TABLE
			. ' WHERE user_id IN (' . implode(', ', $placeholders) . ')';
		if ($before instanceof TimelineCursor) {
			$sql .= ' AND (created_at < :before OR (created_at = :before_equal AND id < :before_id))';
			$parameters['before'] = Time::format($before->publishedAt);
			$parameters['before_equal'] = Time::format($before->publishedAt);
			$parameters['before_id'] = $before->id;
		} elseif ($before !== null) {
			$sql .= ' AND created_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);
		$rows = $this->db->fetchAll($sql, $parameters);

		return array_map(fn (array $row): Boost => $this->hydrate($row), $rows);
	}

	/**
	 * @param list<string> $ids
	 *
	 * @return array{0: list<string>, 1: array<string, string>}
	 */
	private function placeholders(array $ids): array
	{
		$placeholders = [];
		$parameters = [];
		foreach (array_values($ids) as $index => $id) {
			$name = 'id_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $id;
		}

		return [$placeholders, $parameters];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Boost
	{
		return new Boost(
			id: (string) $row['id'],
			userId: (string) $row['user_id'],
			postId: (string) $row['post_id'],
			createdAt: Time::parse((string) $row['created_at']),
		);
	}
}
