<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;

use function array_fill_keys;
use function array_values;

/**
 * Per-user post favourites in memory, for tests, the demo and tools.
 */
final class InMemoryFavouriteRepository implements FavouriteRepository
{
	/**
	 * @var array<string, Favourite>
	 */
	private array $favourites = [];

	public function insert(Favourite $favourite): void
	{
		$key = $this->key($favourite->userId, $favourite->postId);
		if (isset($this->favourites[$key])) {
			throw new DatabaseException('The post is already favourited by this user.');
		}

		$this->favourites[$key] = $favourite;
	}

	public function remove(string $userId, string $postId): bool
	{
		$key = $this->key($userId, $postId);
		if (!isset($this->favourites[$key])) {
			return false;
		}

		unset($this->favourites[$key]);

		return true;
	}

	public function exists(string $userId, string $postId): bool
	{
		return isset($this->favourites[$this->key($userId, $postId)]);
	}

	public function countForPost(string $postId): int
	{
		$count = 0;
		foreach ($this->favourites as $favourite) {
			if ($favourite->postId === $postId) {
				$count++;
			}
		}

		return $count;
	}

	public function countsForPosts(array $postIds): array
	{
		$counts = array_fill_keys(array_values($postIds), 0);
		foreach ($this->favourites as $favourite) {
			if (isset($counts[$favourite->postId])) {
				$counts[$favourite->postId]++;
			}
		}

		return $counts;
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

	private function key(string $userId, string $postId): string
	{
		return $userId . "\0" . $postId;
	}
}
