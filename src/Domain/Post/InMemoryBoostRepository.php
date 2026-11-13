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
 * Local boosts in memory, for tests, the demo and tools.
 */
final class InMemoryBoostRepository implements BoostRepository
{
	/**
	 * @var array<string, Boost>
	 */
	private array $boosts = [];

	public function insert(Boost $boost): void
	{
		if (isset($this->boosts[$boost->id])) {
			throw new DatabaseException('The boost id already exists.');
		}

		foreach ($this->boosts as $existing) {
			if ($existing->userId === $boost->userId && $existing->postId === $boost->postId) {
				throw new DatabaseException('The post is already boosted by this user.');
			}
		}

		$this->boosts[$boost->id] = $boost;
	}

	public function find(string $id): ?Boost
	{
		return $this->boosts[$id] ?? null;
	}

	public function findByUserAndPost(string $userId, string $postId): ?Boost
	{
		foreach ($this->boosts as $boost) {
			if ($boost->userId === $userId && $boost->postId === $postId) {
				return $boost;
			}
		}

		return null;
	}

	public function remove(string $userId, string $postId): bool
	{
		foreach ($this->boosts as $id => $boost) {
			if ($boost->userId !== $userId || $boost->postId !== $postId) {
				continue;
			}

			unset($this->boosts[$id]);

			return true;
		}

		return false;
	}

	public function exists(string $userId, string $postId): bool
	{
		return $this->findByUserAndPost($userId, $postId) !== null;
	}

	public function countForPost(string $postId): int
	{
		$count = 0;
		foreach ($this->boosts as $boost) {
			if ($boost->postId === $postId) {
				$count++;
			}
		}

		return $count;
	}

	public function countsForPosts(array $postIds): array
	{
		$counts = array_fill_keys(array_values($postIds), 0);
		foreach ($this->boosts as $boost) {
			if (isset($counts[$boost->postId])) {
				$counts[$boost->postId]++;
			}
		}

		return $counts;
	}

	public function existsForPosts(string $userId, array $postIds): array
	{
		$exists = array_fill_keys(array_values($postIds), false);
		foreach ($this->boosts as $boost) {
			if ($boost->userId === $userId && isset($exists[$boost->postId])) {
				$exists[$boost->postId] = true;
			}
		}

		return $exists;
	}

	public function timelineForUsers(array $userIds, int $limit, ?DateTimeImmutable $before = null): array
	{
		$users = array_fill_keys(array_values($userIds), true);
		$boosts = array_values(array_filter(
			$this->boosts,
			static fn (Boost $boost): bool => isset($users[$boost->userId])
				&& ($before === null || $boost->createdAt < $before),
		));

		usort($boosts, static function (Boost $a, Boost $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($boosts, 0, max(1, $limit));
	}
}
