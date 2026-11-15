<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Collection;

use AlohaSocial\Core\Platform\Db\DatabaseException;

use function array_values;
use function count;
use function strcmp;
use function usort;

final class InMemoryMediaCollectionRepository implements MediaCollectionRepository
{
	/**
	 * @var array<string, MediaCollection>
	 */
	private array $collections = [];

	/**
	 * @var array<string, MediaCollectionItem>
	 */
	private array $items = [];

	public function insert(MediaCollection $collection): void
	{
		if (isset($this->collections[$collection->id])) {
			throw new DatabaseException('The collection id already exists.');
		}

		$this->collections[$collection->id] = $collection;
	}

	public function update(MediaCollection $collection): void
	{
		if (isset($this->collections[$collection->id])) {
			$this->collections[$collection->id] = $collection;
		}
	}

	public function find(string $id): ?MediaCollection
	{
		return $this->collections[$id] ?? null;
	}

	public function delete(string $id): void
	{
		unset($this->collections[$id]);
		foreach ($this->items as $key => $item) {
			if ($item->collectionId === $id) {
				unset($this->items[$key]);
			}
		}
	}

	public function forOwner(string $ownerId): array
	{
		$collections = array_values(array_filter(
			$this->collections,
			static fn (MediaCollection $collection): bool => $collection->ownerId === $ownerId,
		));
		usort($collections, static function (MediaCollection $a, MediaCollection $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $collections;
	}

	public function forOwnerPage(string $ownerId, int $limit, int $offset): array
	{
		return array_slice($this->forOwner($ownerId), max(0, $offset), max(1, $limit));
	}

	public function countForOwner(string $ownerId): int
	{
		return count($this->forOwner($ownerId));
	}

	public function items(string $collectionId, int $limit, int $offset): array
	{
		$items = array_values(array_filter(
			$this->items,
			static fn (MediaCollectionItem $item): bool => $item->collectionId === $collectionId,
		));
		usort($items, static fn (MediaCollectionItem $a, MediaCollectionItem $b): int => $a->position <=> $b->position);

		return array_slice($items, max(0, $offset), max(1, $limit));
	}

	public function countItems(string $collectionId): int
	{
		$count = 0;
		foreach ($this->items as $item) {
			if ($item->collectionId === $collectionId) {
				$count++;
			}
		}

		return $count;
	}

	public function findItem(string $collectionId, string $postId): ?MediaCollectionItem
	{
		return $this->items[$collectionId . "\0" . $postId] ?? null;
	}

	public function addItem(MediaCollectionItem $item): void
	{
		$key = $item->collectionId . "\0" . $item->postId;
		if (isset($this->items[$key])) {
			throw new DatabaseException('The post is already in this collection.');
		}

		foreach ($this->items as $stored) {
			if ($stored->collectionId === $item->collectionId && $stored->position === $item->position) {
				throw new DatabaseException('The collection position is already taken.');
			}
		}

		$this->items[$key] = $item;
	}

	public function removeItem(string $collectionId, string $postId): bool
	{
		$key = $collectionId . "\0" . $postId;
		if (!isset($this->items[$key])) {
			return false;
		}

		unset($this->items[$key]);

		return true;
	}
}
