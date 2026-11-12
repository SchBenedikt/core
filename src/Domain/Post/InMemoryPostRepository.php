<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

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
		);
	}

	public function publicTimeline(int $limit, ?DateTimeImmutable $before = null): array
	{
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted() && $post->visibility->isPubliclyVisible(),
			)),
			$limit,
			$before,
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

	public function homeTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array
	{
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& ($post->authorId === $userId || $post->visibility->isPubliclyVisible()),
			)),
			$limit,
			$before,
		);
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<Post>
	 */
	private function slice(array $posts, int $limit, ?DateTimeImmutable $before): array
	{
		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		$result = [];
		foreach ($posts as $post) {
			if ($before !== null && $post->publishedAt >= $before) {
				continue;
			}

			$result[] = $post;
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}
}
