<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

/**
 * Storage of superseded post versions.
 *
 * Append-only by contract: nothing in the core updates or deletes a snapshot,
 * so an edit history cannot be rewritten by a later edit. The live version
 * stays in the post table; only replaced versions are stored here.
 */
interface PostRevisionRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the version is already stored
	 */
	public function add(PostRevision $revision): void;

	/**
	 * Every stored version of one post, oldest first.
	 *
	 * @return list<PostRevision>
	 */
	public function forPost(string $postId): array;

	/**
	 * The number of stored versions of one post.
	 */
	public function countForPost(string $postId): int;
}
