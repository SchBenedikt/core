<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\DatabaseException;

/**
 * Publication storage in memory, mirroring the SQL adapter's keys.
 */
final class InMemoryAtprotoPublicationRepository implements AtprotoPublicationRepository
{
	/**
	 * @var array<string, AtprotoPublication>
	 */
	private array $publications = [];

	public function findForPost(string $localUserId, string $postId): ?AtprotoPublication
	{
		return $this->publications[$this->key($localUserId, $postId)] ?? null;
	}

	public function insert(AtprotoPublication $publication): void
	{
		$key = $this->key($publication->localUserId, $publication->postId);
		if (isset($this->publications[$key])) {
			throw new DatabaseException('The AT Protocol publication already exists.');
		}

		foreach ($this->publications as $stored) {
			if ($stored->localUserId === $publication->localUserId && $stored->rkey === $publication->rkey) {
				throw new DatabaseException('The AT Protocol record key is already used by this account.');
			}
		}

		$this->publications[$key] = $publication;
	}

	public function update(AtprotoPublication $publication): void
	{
		$key = $this->key($publication->localUserId, $publication->postId);
		if (!isset($this->publications[$key])) {
			throw new DatabaseException('The AT Protocol publication does not exist.');
		}

		$this->publications[$key] = $publication;
	}

	public function removeForPost(string $localUserId, string $postId): bool
	{
		$key = $this->key($localUserId, $postId);
		if (!isset($this->publications[$key])) {
			return false;
		}

		unset($this->publications[$key]);

		return true;
	}

	private function key(string $localUserId, string $postId): string
	{
		return $localUserId . "\0" . $postId;
	}
}
