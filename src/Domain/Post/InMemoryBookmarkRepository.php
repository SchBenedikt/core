<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

use function array_fill_keys;
use function array_filter;
use function array_slice;
use function array_values;
use function strcmp;
use function usort;

/**
 * Private per-user post bookmarks in memory, for tests and the demo.
 */
final class InMemoryBookmarkRepository implements BookmarkRepository
{
	/**
	 * @var array<string, Bookmark>
	 */
	private array $bookmarks = [];

	public function insert(Bookmark $bookmark): void
	{
		$key = $this->key($bookmark->userId, $bookmark->postId);
		if (isset($this->bookmarks[$key])) {
			throw new DatabaseException('The post is already bookmarked by this user.');
		}

		$this->bookmarks[$key] = $bookmark;
	}

	public function remove(string $userId, string $postId): bool
	{
		$key = $this->key($userId, $postId);
		if (!isset($this->bookmarks[$key])) {
			return false;
		}

		unset($this->bookmarks[$key]);

		return true;
	}

	public function exists(string $userId, string $postId): bool
	{
		return isset($this->bookmarks[$this->key($userId, $postId)]);
	}

	public function find(string $userId, string $postId): ?Bookmark
	{
		return $this->bookmarks[$this->key($userId, $postId)] ?? null;
	}

	public function existsForPosts(string $userId, array $postIds): array
	{
		$exists = array_fill_keys(array_values($postIds), false);
		foreach ($postIds as $postId) {
			if ($this->exists($userId, $postId)) {
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
		$bookmarks = array_values(array_filter(
			$this->bookmarks,
			static function (Bookmark $bookmark) use (
				$userId,
				$before,
				$beforePostId,
				$after,
				$afterPostId,
			): bool {
				if ($bookmark->userId !== $userId) {
					return false;
				}

				if ($before !== null) {
					if ($bookmark->createdAt > $before) {
						return false;
					}

					if ($bookmark->createdAt == $before
						&& ($beforePostId === null || strcmp($bookmark->postId, $beforePostId) >= 0)
					) {
						return false;
					}
				}

				if ($after !== null) {
					if ($bookmark->createdAt < $after) {
						return false;
					}

					if ($bookmark->createdAt == $after
						&& ($afterPostId === null || strcmp($bookmark->postId, $afterPostId) <= 0)
					) {
						return false;
					}
				}

				return true;
			},
		));

		usort($bookmarks, static function (Bookmark $a, Bookmark $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->postId, $a->postId);
		});

		return array_slice($bookmarks, 0, max(1, $limit));
	}

	private function key(string $userId, string $postId): string
	{
		return $userId . "\0" . $postId;
	}
}
