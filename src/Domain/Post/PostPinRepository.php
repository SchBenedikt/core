<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * Owner-scoped profile pins, ordered by an explicit one-based position.
 *
 * The unique pair (owner, post) makes pinning idempotent, and the unique
 * pair (owner, position) keeps five concurrent writers from producing six
 * pins: at most one can take a free slot.
 */
interface PostPinRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the post is
	 *                                                         already pinned or the position is taken
	 */
	public function insert(PostPin $pin): void;

	public function find(string $ownerId, string $postId): ?PostPin;

	/**
	 * @return list<PostPin> ordered by position
	 */
	public function forOwner(string $ownerId): array;

	public function count(string $ownerId): int;

	public function remove(string $ownerId, string $postId): bool;

	/**
	 * Removes a post from every owner's pins. Used when the post is deleted.
	 */
	public function removeForPost(string $postId): void;
}
