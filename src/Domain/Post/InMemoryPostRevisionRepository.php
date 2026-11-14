<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;

/**
 * Revision storage in memory, for tests and the reference assembly.
 *
 * It keeps the same append-only and uniqueness rules as the SQL adapter; the
 * shared contract test is what keeps the two interchangeable.
 */
final class InMemoryPostRevisionRepository implements PostRevisionRepository
{
	/**
	 * @var array<string, array<int, PostRevision>>
	 */
	private array $revisions = [];

	public function add(PostRevision $revision): void
	{
		if (isset($this->revisions[$revision->postId][$revision->revision])) {
			throw new DatabaseException('The post revision already exists.');
		}

		$this->revisions[$revision->postId][$revision->revision] = $revision;
	}

	public function forPost(string $postId): array
	{
		$versions = $this->revisions[$postId] ?? [];
		ksort($versions);

		return array_values($versions);
	}

	public function countForPost(string $postId): int
	{
		return count($this->revisions[$postId] ?? []);
	}
}
