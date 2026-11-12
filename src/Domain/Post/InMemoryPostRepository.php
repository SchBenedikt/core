<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

/**
 * Post storage in memory, for tests, the demo and tools.
 *
 * It applies exactly the ordering and visibility rules of the SQL
 * implementation, so behaviour cannot differ between the two unnoticed.
 */
final class InMemoryPostRepository implements PostRepository
{
	/**
	 * @var array<string, Post>
	 */
	private array $posts = [];

	public function insert(Post $post): void
	{
		if (isset($this->posts[$post->id])) {
			throw new DatabaseException('The post id already exists.');
		}

		$this->posts[$post->id] = $post;
	}

	public function find(string $id): ?Post
	{
		return $this->posts[$id] ?? null;
	}

	public function softDelete(string $id, DateTimeImmutable $deletedAt): void
	{
		$post = $this->posts[$id] ?? null;
		if ($post === null || $post->isDeleted()) {
			return;
		}

		$this->posts[$id] = new Post(
			$post->id,
			$post->authorId,
			$post->content,
			$post->visibility,
			$post->publishedAt,
			$post->contentWarning,
			$deletedAt,
			$post->attachmentIds,
		);
	}

	public function publicTimeline(
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted() && $post->visibility->isPubliclyVisible(),
			)),
			$limit,
			$before,
			$beforeId,
			$after,
			$afterId,
		);
	}

	public function authorTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& $post->authorId === $userId
					&& $post->visibility->isPubliclyVisible(),
			)),
			$limit,
			$before,
			$beforeId,
			$after,
			$afterId,
		);
	}

	public function authorPosts(string $userId): array
	{
		$posts = array_values(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted()
				&& $post->authorId === $userId
				&& $post->visibility->isPubliclyVisible(),
		));

		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $posts;
	}

	public function authorPostsPage(string $userId, int $limit, int $offset): array
	{
		return array_slice($this->authorPosts($userId), max(0, $offset), max(0, $limit));
	}

	public function authorPostCount(string $userId): int
	{
		return count($this->authorPosts($userId));
	}

	public function ownTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array
	{
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted() && $post->authorId === $userId,
			)),
			$limit,
			$before,
		);
	}

	public function attachmentIdsFor(array $postIds): array
	{
		$result = [];
		foreach ($postIds as $postId) {
			if (isset($this->posts[$postId])) {
				$result[$postId] = $this->posts[$postId]->attachmentIds;
			}
		}

		return $result;
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<Post>
	 */
	private function slice(
		array $posts,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		$result = [];
		foreach ($posts as $post) {
			if ($before !== null) {
				if ($post->publishedAt > $before) {
					continue;
				}

				if ($post->publishedAt == $before && $beforeId !== null && strcmp($post->id, $beforeId) >= 0) {
					continue;
				}

				if ($post->publishedAt == $before && $beforeId === null) {
					continue;
				}
			}

			if ($after !== null) {
				if ($post->publishedAt < $after) {
					continue;
				}

				if ($post->publishedAt == $after && $afterId !== null && strcmp($post->id, $afterId) <= 0) {
					continue;
				}

				if ($post->publishedAt == $after && $afterId === null) {
					continue;
				}
			}

			$result[] = $post;
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}
}
