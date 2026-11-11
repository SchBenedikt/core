<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

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
	}

	public function find(string $id): ?Post
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function softDelete(string $id, DateTimeImmutable $deletedAt): void
	{
		$this->db->execute(
			'UPDATE ' . PostSchema::TABLE
			. ' SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL',
			['id' => $id, 'deleted_at' => Time::format($deletedAt)],
		);
	}

	public function publicTimeline(int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND visibility IN (:public, :unlisted)';
		$parameters = [
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		];

		if ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
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

	public function homeTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL'
			. ' AND (author_id = :author OR visibility IN (:public, :unlisted))';
		$parameters = [
			'author' => $userId,
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		];

		if ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
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
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<Post>
	 */
	private function hydrateAll(array $rows): array
	{
		return array_map(fn (array $row): Post => $this->hydrate($row), $rows);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Post
	{
		return new Post(
			id: (string) $row['id'],
			authorId: (string) $row['author_id'],
			content: (string) $row['content'],
			visibility: Visibility::from((string) $row['visibility']),
			publishedAt: Time::parse((string) $row['published_at']),
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			deletedAt: $row['deleted_at'] === null ? null : Time::parse((string) $row['deleted_at']),
		);
	}
}
