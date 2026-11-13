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

use function array_values;
use function implode;

/**
 * Post storage in SQL.
 *
 * Timestamps are stored as fixed-width UTC strings (see {@see Time}), which
 * keeps string comparison chronological on SQLite, PostgreSQL and MySQL
 * alike and makes the timeline query a single indexed range scan.
 */
final class PdoPostRepository implements PostRepository, PostSearchRepository, PostCount
{
	private const COLUMNS = 'id, author_id, content, content_warning, visibility, in_reply_to_id, published_at, deleted_at';

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
					. ' (id, author_id, content, content_warning, visibility, in_reply_to_id, published_at, deleted_at)'
					. ' VALUES (:id, :author_id, :content, :content_warning, :visibility, :in_reply_to_id, :published_at, :deleted_at)',
					[
						'id' => $post->id,
						'author_id' => $post->authorId,
						'content' => $post->content,
						'content_warning' => $post->contentWarning,
						'visibility' => $post->visibility->value,
						'in_reply_to_id' => $post->inReplyToId,
						'published_at' => Time::format($post->publishedAt),
						'deleted_at' => $post->deletedAt === null ? null : Time::format($post->deletedAt),
					],
				);

				foreach ($post->recipientIds as $position => $recipientId) {
					$this->db->execute(
						'INSERT INTO ' . PostSchema::RECIPIENT_TABLE
						. ' (post_id, recipient_id, position) VALUES (:post_id, :recipient_id, :position)',
						[
							'post_id' => $post->id,
							'recipient_id' => $recipientId,
							'position' => $position,
						],
					);
				}

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

				foreach (Hashtags::extract($post->content) as $position => $hashtag) {
					$this->db->execute(
						'INSERT INTO ' . PostSchema::HASHTAG_TABLE
						. ' (post_id, hashtag, position) VALUES (:post_id, :hashtag, :position)',
						[
							'post_id' => $post->id,
							'hashtag' => $hashtag,
							'position' => $position,
						],
					);
				}

				if ($post->poll !== null) {
					$this->db->execute(
						'INSERT INTO ' . PollSchema::TABLE
						. ' (post_id, multiple, hide_totals, expires_at, votes_count, voters_count)'
						. ' VALUES (:post_id, :multiple, :hide_totals, :expires_at, :votes_count, :voters_count)',
						[
							'post_id' => $post->id,
							'multiple' => $post->poll->multiple ? 1 : 0,
							'hide_totals' => $post->poll->hideTotals ? 1 : 0,
							'expires_at' => Time::format($post->poll->expiresAt),
							'votes_count' => $post->poll->votesCount,
							'voters_count' => $post->poll->votersCount,
						],
					);

					foreach ($post->poll->options as $position => $option) {
						$this->db->execute(
							'INSERT INTO ' . PollSchema::OPTION_TABLE
							. ' (post_id, position, title, votes_count) VALUES (:post_id, :position, :title, :votes_count)',
							[
								'post_id' => $post->id,
								'position' => $position,
								'title' => $option->title,
								'votes_count' => $option->votesCount,
							],
						);
					}
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

		if ($row === null) {
			return null;
		}

		return $this->hydrate(
			$row,
			$this->attachmentIdsFor([$id])[$id] ?? [],
			$this->pollsFor([$id])[$id] ?? null,
			$this->recipientIdsFor([$id])[$id] ?? [],
		);
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

	public function hashtagTimeline(
		string $hashtag,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE . ' p'
			. ' INNER JOIN ' . PostSchema::HASHTAG_TABLE . ' h ON h.post_id = p.id'
			. ' WHERE p.deleted_at IS NULL AND p.visibility IN (:public, :unlisted)'
			. ' AND h.hashtag = :hashtag';
		$parameters = [
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
			'hashtag' => $hashtag,
		];

		if ($before !== null) {
			if ($beforeId === null) {
				$sql .= ' AND p.published_at < :before';
			} else {
				$sql .= ' AND (p.published_at < :before OR (p.published_at = :before AND p.id < :before_id))';
				$parameters['before_id'] = $beforeId;
			}
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function followedHashtagTimeline(
		array $hashtags,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		if ($hashtags === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		];
		foreach (array_values($hashtags) as $index => $hashtag) {
			$name = 'hashtag_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $hashtag;
		}

		$sql = 'SELECT DISTINCT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE . ' p'
			. ' INNER JOIN ' . PostSchema::HASHTAG_TABLE . ' h ON h.post_id = p.id'
			. ' WHERE p.deleted_at IS NULL AND p.visibility IN (:public, :unlisted)'
			. ' AND h.hashtag IN (' . implode(', ', $placeholders) . ')';

		if ($before !== null) {
			if ($beforeId === null) {
				$sql .= ' AND p.published_at < :before';
			} else {
				$sql .= ' AND (p.published_at < :before OR (p.published_at = :before AND p.id < :before_id))';
				$parameters['before_id'] = $beforeId;
			}
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function authorTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
		bool $includeFollowers = false,
	): array {
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id = :author'
			. ' AND visibility IN (:public, :unlisted'
			. ($includeFollowers ? ', :followers' : '') . ')';
		$parameters = [
			'author' => $userId,
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
		];
		if ($includeFollowers) {
			$parameters['followers'] = Visibility::Followers->value;
		}

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

	public function countLive(): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . PostSchema::TABLE . ' WHERE deleted_at IS NULL',
		);
	}

	public function timelineForAuthors(array $authorIds, int $limit, ?DateTimeImmutable $before = null): array
	{
		if ($authorIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [];
		foreach (array_values($authorIds) as $index => $authorId) {
			$name = 'author_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $authorId;
		}

		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND author_id IN (' . implode(', ', $placeholders) . ')'
			. ' AND visibility IN (:public, :unlisted, :followers)';
		$parameters['public'] = Visibility::Public->value;
		$parameters['unlisted'] = Visibility::Unlisted->value;
		$parameters['followers'] = Visibility::Followers->value;

		if ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function repliesTo(string $postId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND in_reply_to_id = :post_id';
		$parameters = ['post_id' => $postId];

		if ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	public function replyCount(string $postId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND in_reply_to_id = :post_id',
			['post_id' => $postId],
		);
	}

	public function replyCountsForPosts(array $postIds): array
	{
		$counts = array_fill_keys(array_values($postIds), 0);
		if ($postIds === []) {
			return $counts;
		}

		$placeholders = [];
		$parameters = [];
		foreach (array_values($postIds) as $index => $postId) {
			$name = 'post_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $postId;
		}

		$rows = $this->db->fetchAll(
			'SELECT in_reply_to_id, COUNT(*) AS total FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL AND in_reply_to_id IN (' . implode(', ', $placeholders) . ')'
			. ' GROUP BY in_reply_to_id',
			$parameters,
		);

		foreach ($rows as $row) {
			$postId = (string) $row['in_reply_to_id'];
			if (isset($counts[$postId])) {
				$counts[$postId] = (int) $row['total'];
			}
		}

		return $counts;
	}

	public function ownTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE . ' p'
			. ' WHERE p.deleted_at IS NULL AND (p.author_id = :author'
			. ' OR (p.visibility = :direct AND EXISTS ('
			. ' SELECT 1 FROM ' . PostSchema::RECIPIENT_TABLE . ' r'
			. ' WHERE r.post_id = p.id AND r.recipient_id = :recipient)))';
		$parameters = [
			'author' => $userId,
			'recipient' => $userId,
			'direct' => Visibility::Direct->value,
		];

		if ($before !== null) {
			$sql .= ' AND p.published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $this->limit($limit);

		return $this->hydrateAll($this->db->fetchAll($sql, $parameters));
	}

	/**
	 * @param list<string>|null $authorIds
	 *
	 * @return list<Post>
	 */
	public function search(
		string $query,
		int $limit,
		int $offset,
		?array $authorIds = null,
	): array {
		$query = trim($query);
		if ($query === '' || $limit < 1 || $authorIds === []) {
			return [];
		}

		$parameters = [
			'public' => Visibility::Public->value,
			'unlisted' => Visibility::Unlisted->value,
			'query' => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)) . '%',
		];
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . PostSchema::TABLE
			. ' WHERE deleted_at IS NULL'
			. ' AND visibility IN (:public, :unlisted)'
			. " AND LOWER(content) LIKE :query ESCAPE '!'";

		if ($authorIds !== null) {
			$placeholders = [];
			foreach (array_values($authorIds) as $index => $authorId) {
				$name = 'author_' . $index;
				$placeholders[] = ':' . $name;
				$parameters[$name] = $authorId;
			}
			$sql .= ' AND author_id IN (' . implode(', ', $placeholders) . ')';
		}

		$sql .= ' ORDER BY published_at DESC, id DESC'
			. ' LIMIT ' . $this->limit($limit)
			. ' OFFSET ' . $this->offset($offset);

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
	 * @param list<string> $postIds
	 *
	 * @return array<string, list<string>>
	 */
	private function recipientIdsFor(array $postIds): array
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
			'SELECT post_id, recipient_id FROM ' . PostSchema::RECIPIENT_TABLE
			. ' WHERE post_id IN (' . implode(', ', $placeholders) . ')'
			. ' ORDER BY post_id, position',
			$parameters,
		);

		$result = [];
		foreach ($rows as $row) {
			$result[(string) $row['post_id']][] = (string) $row['recipient_id'];
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
		$polls = $this->pollsFor($ids);
		$recipients = $this->recipientIdsFor($ids);

		return array_map(
			fn (array $row): Post => $this->hydrate(
				$row,
				$attachmentIds[(string) $row['id']] ?? [],
				$polls[(string) $row['id']] ?? null,
				$recipients[(string) $row['id']] ?? [],
			),
			$rows,
		);
	}

	/**
	 * Polls for a set of posts, in one query for the poll rows and one for
	 * their ordered options instead of a query per timeline entry.
	 *
	 * @param list<string> $postIds
	 *
	 * @return array<string, Poll>
	 */
	private function pollsFor(array $postIds): array
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
			'SELECT post_id, multiple, hide_totals, expires_at, votes_count, voters_count FROM '
			. PollSchema::TABLE
			. ' WHERE post_id IN (' . implode(', ', $placeholders) . ')',
			$parameters,
		);
		if ($rows === []) {
			return [];
		}

		$optionRows = $this->db->fetchAll(
			'SELECT post_id, title, votes_count FROM ' . PollSchema::OPTION_TABLE
			. ' WHERE post_id IN (' . implode(', ', $placeholders) . ')'
			. ' ORDER BY post_id, position',
			$parameters,
		);
		$options = [];
		foreach ($optionRows as $optionRow) {
			$options[(string) $optionRow['post_id']][] = new PollOption(
				(string) $optionRow['title'],
				(int) $optionRow['votes_count'],
			);
		}

		$polls = [];
		foreach ($rows as $row) {
			$postId = (string) $row['post_id'];
			$polls[$postId] = new Poll(
				options: $options[$postId] ?? [],
				multiple: (int) $row['multiple'] === 1,
				hideTotals: (int) $row['hide_totals'] === 1,
				expiresAt: Time::parse((string) $row['expires_at']),
				votesCount: (int) $row['votes_count'],
				votersCount: (int) $row['voters_count'],
			);
		}

		return $polls;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(
		array $row,
		array $attachmentIds = [],
		?Poll $poll = null,
		array $recipientIds = [],
	): Post {
		return new Post(
			id: (string) $row['id'],
			authorId: (string) $row['author_id'],
			content: (string) $row['content'],
			visibility: Visibility::from((string) $row['visibility']),
			publishedAt: Time::parse((string) $row['published_at']),
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			deletedAt: $row['deleted_at'] === null ? null : Time::parse((string) $row['deleted_at']),
			attachmentIds: $attachmentIds,
			poll: $poll,
			recipientIds: $recipientIds,
			inReplyToId: $row['in_reply_to_id'] === null ? null : (string) $row['in_reply_to_id'],
		);
	}
}
