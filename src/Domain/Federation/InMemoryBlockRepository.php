<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Per-user block storage in memory, for tests, the demo and tools.
 *
 * It mirrors the SQL adapter's uniqueness, ordering and ownership rules; the
 * shared contract test is what keeps the two interchangeable.
 */
final class InMemoryBlockRepository implements BlockRepository
{
	/**
	 * @var array<string, Block>
	 */
	private array $blocks = [];

	public function insert(Block $block): void
	{
		if (isset($this->blocks[$block->id])) {
			throw new \AlohaSocial\Core\Platform\Db\DatabaseException('The block id already exists.');
		}

		foreach ($this->blocks as $stored) {
			if ($stored->localUserId === $block->localUserId && $stored->actorId === $block->actorId) {
				throw new \AlohaSocial\Core\Platform\Db\DatabaseException('The actor is already blocked by this user.');
			}
		}

		$this->blocks[$block->id] = $block;
	}

	public function findByActor(string $localUserId, string $actorId): ?Block
	{
		foreach ($this->blocks as $block) {
			if ($block->localUserId === $localUserId && $block->actorId === $actorId) {
				return $block;
			}
		}

		return null;
	}

	public function findById(string $id): ?Block
	{
		return $this->blocks[$id] ?? null;
	}

	public function remove(string $id, string $localUserId): bool
	{
		$block = $this->blocks[$id] ?? null;
		if ($block === null || $block->localUserId !== $localUserId) {
			return false;
		}

		unset($this->blocks[$id]);

		return true;
	}

	public function blocks(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$matching = array_values(array_filter(
			$this->blocks,
			static fn (Block $block): bool => $block->localUserId === $localUserId
				&& ($before === null || $block->blockedAt < $before),
		));

		usort($matching, static function (Block $a, Block $b): int {
			$byTime = $b->blockedAt <=> $a->blockedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($matching, 0, max(1, $limit));
	}

	public function actorIds(string $localUserId): array
	{
		return array_map(
			static fn (Block $block): string => $block->actorId,
			$this->blocks($localUserId, PHP_INT_MAX),
		);
	}

	public function count(string $localUserId): int
	{
		$count = 0;
		foreach ($this->blocks as $block) {
			if ($block->localUserId === $localUserId) {
				$count++;
			}
		}

		return $count;
	}
}
