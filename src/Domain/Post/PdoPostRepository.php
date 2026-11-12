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

/**
 * Post storage in SQL.
 *
 * Timestamps are stored as fixed-width UTC strings (see {@see Time}), which
 * keeps string comparison chronological on SQLite, PostgreSQL and MySQL
 * alike and makes the timeline query a single indexed range scan.
 */
final class PdoPostRepository implements PostRepository
{
	private const COLUMNS = 'id, author_id, content, content_warning, visibility, published_at, deleted_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Post $post): void
	{
		try {
			$this->db->transaction(function () use ($post): void {
				$this->db->execute(
					'INSERT INTO ' . PostSchema::TABLE
					. ' (id, author_id, content, content_warning, visibility, published_at, deleted_at)'
					. ' VALUES (:id, :author_id, :content, :content_warning, :visibility, :published_at, :deleted_at)',
					[
						'id' => $post->id,
						'author_id' => $post->authorId,
						'content' => $post->content,
						'content_warning' => $post->contentWarning,
						'visibility' => $post->visibility->value,
						'published_at' => Time::format($post->publishedAt),
						'deleted_at' => $post->deletedAt === null ? null : Time::format($post->deletedAt),
					],
				);

				foreach ($post->attachmentIds as $position => $attachmentId) {
					$this->db->execute(
						'INSERT INTO ' . PostSchema::ATTACHMENT_TABLE
						. ' (post_id, attachment_id, position) VALUES (:post_id, :attachment_id, :position)',
						[
							'post_id' => $post->id,
							'attachment_id' => $attachmentId,
							'position' => $position,
						],
					);
				}
			});
		} catch (PDOException $exception) {
			throw new DatabaseException('The post could not be stored.', 0, $exception);
		}
	}

	public function find(string $id): ?Post
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row, $this->attachmentIdsFor([$id])[$id] ?? []);
	}

	public function softDelete(string $id, DateTimeImmutable $deletedAt): void
	{
		$this->db->execute(
			'UPDATE ' . PostSchema::TABLE
			. ' SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL',
			['id' => $id, 'deleted_at' => Time::format($deletedAt)],
		);
	}

	public function publicTimeline(
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND visibility IN (:public, :unlisted)';
		$parameters = [
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		];

		if ($before !== null) {
			if ($beforeId === null) {
				$sql .= ' AND published_at < :before';
			} else {
				$sql .= ' AND (published_at < :before OR (published_at = :before AND id < :before_id))';
				$parameters['before_id'] = $beforeId;
			}
			$parameters['before'] = Time::format($before);
		}

		if ($after !== null) {
			if ($afterId === null) {
				$sql .= ' AND published_at > :after';
			} else {
				$sql .= ' AND (published_at > :after OR (published_at = :after AND id > :after_id))';
				$parameters['after_id'] = $afterId;
			}
			$parameters['after'] = Time::format($after);
		}

		$sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function authorTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id = :author'
			. ' AND visibility IN (:public, :unlisted)';
		$parameters = [
			'author' => $userId,
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		];

		if ($before !== null) {
			if ($beforeId === null) {
				$sql .= ' AND published_at < :before';
			} else {
				$sql .= ' AND (published_at < :before OR (published_at = :before AND id < :before_id))';
				$parameters['before_id'] = $beforeId;
			}
			$parameters['before'] = Time::format($before);
		}

		if ($after !== null) {
			if ($afterId === null) {
				$sql .= ' AND published_at > :after';
			} else {
				$sql .= ' AND (published_at > :after OR (published_at = :after AND id > :after_id))';
				$parameters['after_id'] = $afterId;
			}
			$parameters['after'] = Time::format($after);
		}

		$sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function authorPosts(string $userId): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id = :author'
			. ' AND visibility IN (:public, :unlisted)'
			. ' ORDER BY published_at DESC, id DESC';

		return $this->hydrateAll($this->db->fetchAll($sql, [
			'author' => $userId,
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		]));
	}

	public function authorPostsPage(string $userId, int $limit, int $offset): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id = :author'
			. ' AND visibility IN (:public, :unlisted)'
			. ' ORDER BY published_at DESC, id DESC'
			. ' LIMIT ' . $this->limit($limit)
			. ' OFFSET ' . $this->offset($offset);

		return $this->hydrateAll($this->db->fetchAll($sql, [
			'author' => $userId,
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		]));
	}

	public function authorPostCount(string $userId): int
	{
		$value = $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id = :author'
			. ' AND visibility IN (:public, :unlisted)',
			[
				'author' => $userId,
				'public' => Visibility::Public->value,
				'unlisted' => Visibility::Unlisted->value,
			],
		);

		return (int) $value;
	}

	public function ownTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id = :author';
		$parameters = ['author' => $userId];

		if ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function isAttachmentLinked(string $attachmentId): bool
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . PostSchema::ATTACHMENT_TABLE
			. ' WHERE attachment_id = :attachment_id',
			['attachment_id' => $attachmentId],
		) > 0;
	}

	public function attachmentIdsFor(array $postIds): array
	{
		if ($postIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [];
		foreach (array_values($postIds) as $index => $postId) {
			$name = 'post_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $postId;
		}

		$rows = $this->db->fetchAll(
			'SELECT post_id, attachment_id FROM ' . PostSchema::ATTACHMENT_TABLE
			. ' WHERE post_id IN (' . implode(', ', $placeholders) . ')'
			. ' ORDER BY post_id, position',
			$parameters,
		);

		$result = [];
		foreach ($rows as $row) {
			$result[(string) $row['post_id']][] = (string) $row['attachment_id'];
		}

		return $result;
	}

	/**
	 * Limits are integers the service already clamped; inlining one is what
	 * keeps the statement identical on drivers that refuse a bound LIMIT.
	 */
	private function limit(int $limit): string
	{
		return (string) max(1, $limit);
	}

	/**
	 * Offsets are non-negative integers already clamped by the caller; inlining
	 * one keeps the statement portable across the supported drivers.
	 */
	private function offset(int $offset): string
	{
		return (string) max(0, $offset);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<Post>
	 */
	private function hydrateAll(array $rows): array
	{
		$ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
		$attachmentIds = $this->attachmentIdsFor($ids);

		return array_map(
			fn (array $row): Post => $this->hydrate($row, $attachmentIds[(string) $row['id']] ?? []),
			$rows,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row, array $attachmentIds = []): Post
	{
		return new Post(
			id: (string) $row['id'],
			authorId: (string) $row['author_id'],
			content: (string) $row['content'],
			visibility: Visibility::from((string) $row['visibility']),
			publishedAt: Time::parse((string) $row['published_at']),
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			deletedAt: $row['deleted_at'] === null ? null : Time::parse((string) $row['deleted_at']),
			attachmentIds: $attachmentIds,
		);
	}
}
