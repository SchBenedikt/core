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
 * Private per-user post bookmarks in SQL.
 *
 * The composite primary key is the authority on idempotence. Timestamps use
 * the canonical fixed-width form so cursor ordering matches the in-memory
 * adapter.
 */
final class PdoBookmarkRepository implements BookmarkRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Bookmark $bookmark): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . BookmarkSchema::TABLE
				. ' (user_id, post_id, created_at)'
				. ' VALUES (:user_id, :post_id, :created_at)',
				[
					'user_id' => $bookmark->userId,
					'post_id' => $bookmark->postId,
					'created_at' => Time::format($bookmark->createdAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The bookmark could not be stored.', 0, $exception);
		}
	}

	public function remove(string $userId, string $postId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . BookmarkSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		) > 0;
	}

	public function exists(string $userId, string $postId): bool
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . BookmarkSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		) > 0;
	}

	public function find(string $userId, string $postId): ?Bookmark
	{
		$row = $this->db->fetchOne(
			'SELECT user_id, post_id, created_at FROM ' . BookmarkSchema::TABLE
			. ' WHERE user_id = :user_id AND post_id = :post_id',
			['user_id' => $userId, 'post_id' => $postId],
		);

		return $row === null ? null : $this->hydrate($row);
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
			'SELECT post_id FROM ' . BookmarkSchema::TABLE
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

	public function forUser(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforePostId = null,
		?DateTimeImmutable $after = null,
		?string $afterPostId = null,
	): array {
		$sql = 'SELECT user_id, post_id, created_at FROM ' . BookmarkSchema::TABLE
			. ' WHERE user_id = :user_id';
		$parameters = ['user_id' => $userId];

		if ($before !== null) {
			if ($beforePostId === null) {
				$sql .= ' AND created_at < :before';
			} else {
				$sql .= ' AND (created_at < :before OR (created_at = :before AND post_id < :before_post))';
				$parameters['before_post'] = $beforePostId;
			}
			$parameters['before'] = Time::format($before);
		}

		if ($after !== null) {
			if ($afterPostId === null) {
				$sql .= ' AND created_at > :after';
			} else {
				$sql .= ' AND (created_at > :after OR (created_at = :after AND post_id > :after_post))';
				$parameters['after_post'] = $afterPostId;
			}
			$parameters['after'] = Time::format($after);
		}

		$sql .= ' ORDER BY created_at DESC, post_id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): Bookmark => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
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

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Bookmark
	{
		return new Bookmark(
			userId: (string) $row['user_id'],
			postId: (string) $row['post_id'],
			createdAt: Time::parse((string) $row['created_at']),
		);
	}
}
