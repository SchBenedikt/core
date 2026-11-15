<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Collection;

/**
 * Persistence for owner-scoped media collections and their ordered members.
 *
 * The repository enforces identity and position uniqueness; visibility and
 * post eligibility belong in the service, which has the viewer and media
 * metadata needed to decide them.
 */
interface MediaCollectionRepository
{
	public function insert(MediaCollection $collection): void;

	public function update(MediaCollection $collection): void;

	public function find(string $id): ?MediaCollection;

	public function delete(string $id): void;

	/**
	 * @return list<MediaCollection> newest first
	 */
	public function forOwner(string $ownerId): array;

	/**
	 * @return list<MediaCollection> newest first
	 */
	public function forOwnerPage(string $ownerId, int $limit, int $offset): array;

	public function countForOwner(string $ownerId): int;

	/**
	 * @return list<MediaCollectionItem> ordered by position
	 */
	public function items(string $collectionId, int $limit, int $offset): array;

	public function countItems(string $collectionId): int;

	public function findItem(string $collectionId, string $postId): ?MediaCollectionItem;

	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the post or
	 *                                                         position is already present
	 */
	public function addItem(MediaCollectionItem $item): void;

	public function removeItem(string $collectionId, string $postId): bool;
}
