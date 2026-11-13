<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * In-memory remote thread cache, for tests and the reference assembly.
 */
final class InMemoryRemoteThreadRepository implements RemoteThreadRepository
{
	/**
	 * @var array<string, RemoteThreadPost>
	 */
	private array $posts = [];

	public function upsert(RemoteThreadPost $post): void
	{
		$this->posts[$post->objectId] = $post;
	}

	public function find(string $objectId): ?RemoteThreadPost
	{
		return $this->posts[$objectId] ?? null;
	}

	public function children(string $parentObjectId, int $limit): array
	{
		if ($limit <= 0) {
			return [];
		}

		$children = array_values(array_filter(
			$this->posts,
			static fn (RemoteThreadPost $post): bool => $post->inReplyToId === $parentObjectId,
		));

		usort($children, static function (RemoteThreadPost $a, RemoteThreadPost $b): int {
			$byTime = $a->publishedAt <=> $b->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($a->objectId, $b->objectId);
		});

		return array_slice($children, 0, $limit);
	}

	public function countFetchedBefore(DateTimeImmutable $before): int
	{
		$count = 0;
		foreach ($this->posts as $post) {
			if ($post->fetchedAt < $before) {
				$count++;
			}
		}

		return $count;
	}

	public function deleteFetchedBefore(DateTimeImmutable $before, int $limit = 500): int
	{
		$expired = array_values(array_filter(
			$this->posts,
			static fn (RemoteThreadPost $post): bool => $post->fetchedAt < $before,
		));

		usort($expired, static function (RemoteThreadPost $left, RemoteThreadPost $right): int {
			$byFetch = $left->fetchedAt <=> $right->fetchedAt;

			return $byFetch !== 0 ? $byFetch : strcmp($left->objectId, $right->objectId);
		});

		$removed = 0;
		foreach (array_slice($expired, 0, max(0, $limit)) as $post) {
			unset($this->posts[$post->objectId]);
			$removed++;
		}

		return $removed;
	}
}
