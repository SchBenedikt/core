<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Collection;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PDOException;

final class PdoMediaCollectionRepository implements MediaCollectionRepository
{
	private const COLUMNS = 'id, owner_id, title, description, audience, created_at, updated_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(MediaCollection $collection): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . MediaCollectionSchema::TABLE . ' (' . self::COLUMNS . ')'
				. ' VALUES (:id, :owner_id, :title, :description, :audience, :created_at, :updated_at)',
				$this->parameters($collection),
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The collection could not be stored.', 0, $exception);
		}
	}

	public function update(MediaCollection $collection): void
	{
		$this->db->execute(
			'UPDATE ' . MediaCollectionSchema::TABLE
			. ' SET title = :title, description = :description, audience = :audience, updated_at = :updated_at'
			. ' WHERE id = :id AND owner_id = :owner_id',
			[
				'id' => $collection->id,
				'owner_id' => $collection->ownerId,
				'title' => $collection->title,
				'description' => $collection->description,
				'audience' => $collection->audience->value,
				'updated_at' => Time::format($collection->updatedAt),
			],
		);
	}

	public function find(string $id): ?MediaCollection
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . MediaCollectionSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function delete(string $id): void
	{
		$this->db->transaction(function () use ($id): void {
			$this->db->execute(
				'DELETE FROM ' . MediaCollectionSchema::ITEM_TABLE . ' WHERE collection_id = :id',
				['id' => $id],
			);
			$this->db->execute(
				'DELETE FROM ' . MediaCollectionSchema::TABLE . ' WHERE id = :id',
				['id' => $id],
			);
		});
	}

	public function forOwner(string $ownerId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . MediaCollectionSchema::TABLE
			. ' WHERE owner_id = :owner_id ORDER BY created_at DESC, id DESC',
			['owner_id' => $ownerId],
		);

		return array_map(fn (array $row): MediaCollection => $this->hydrate($row), $rows);
	}

	public function forOwnerPage(string $ownerId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . MediaCollectionSchema::TABLE
			. ' WHERE owner_id = :owner_id ORDER BY created_at DESC, id DESC'
			. ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
			['owner_id' => $ownerId],
		);

		return array_map(fn (array $row): MediaCollection => $this->hydrate($row), $rows);
	}

	public function countForOwner(string $ownerId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . MediaCollectionSchema::TABLE . ' WHERE owner_id = :owner_id',
			['owner_id' => $ownerId],
		);
	}

	public function items(string $collectionId, int $limit, int $offset): array
	{
		$rows = $this->db->fetchAll(
			'SELECT collection_id, post_id, position, added_at FROM ' . MediaCollectionSchema::ITEM_TABLE
			. ' WHERE collection_id = :collection_id ORDER BY position ASC'
			. ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
			['collection_id' => $collectionId],
		);

		return array_map(fn (array $row): MediaCollectionItem => $this->hydrateItem($row), $rows);
	}

	public function countItems(string $collectionId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . MediaCollectionSchema::ITEM_TABLE . ' WHERE collection_id = :collection_id',
			['collection_id' => $collectionId],
		);
	}

	public function findItem(string $collectionId, string $postId): ?MediaCollectionItem
	{
		$row = $this->db->fetchOne(
			'SELECT collection_id, post_id, position, added_at FROM ' . MediaCollectionSchema::ITEM_TABLE
			. ' WHERE collection_id = :collection_id AND post_id = :post_id',
			['collection_id' => $collectionId, 'post_id' => $postId],
		);

		return $row === null ? null : $this->hydrateItem($row);
	}

	public function addItem(MediaCollectionItem $item): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . MediaCollectionSchema::ITEM_TABLE
				. ' (collection_id, post_id, position, added_at) VALUES (:collection_id, :post_id, :position, :added_at)',
				[
					'collection_id' => $item->collectionId,
					'post_id' => $item->postId,
					'position' => $item->position,
					'added_at' => Time::format($item->addedAt),
				],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The post could not be added to the collection.', 0, $exception);
		}
	}

	public function removeItem(string $collectionId, string $postId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . MediaCollectionSchema::ITEM_TABLE
			. ' WHERE collection_id = :collection_id AND post_id = :post_id',
			['collection_id' => $collectionId, 'post_id' => $postId],
		) > 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(MediaCollection $collection): array
	{
		return [
			'id' => $collection->id,
			'owner_id' => $collection->ownerId,
			'title' => $collection->title,
			'description' => $collection->description,
			'audience' => $collection->audience->value,
			'created_at' => Time::format($collection->createdAt),
			'updated_at' => Time::format($collection->updatedAt),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): MediaCollection
	{
		return new MediaCollection(
			(string) $row['id'],
			(string) $row['owner_id'],
			(string) $row['title'],
			(string) $row['description'],
			\AlohaSocial\Core\Domain\Post\Visibility::from((string) $row['audience']),
			Time::parse((string) $row['created_at']),
			Time::parse((string) $row['updated_at']),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrateItem(array $row): MediaCollectionItem
	{
		return new MediaCollectionItem(
			(string) $row['collection_id'],
			(string) $row['post_id'],
			(int) $row['position'],
			Time::parse((string) $row['added_at']),
		);
	}
}
