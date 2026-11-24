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

	public function findForPost(string $localUserId, string $postId, ?string $accountDid = null): ?AtprotoPublication
	{
		if ($accountDid !== null) { return $this->publications[$this->key($localUserId, $postId, $accountDid)] ?? null; }
		return $this->findAllForPost($localUserId, $postId)[0] ?? null;
	}

	public function findAllForPost(string $localUserId, string $postId): array
	{
		$matches = array_filter($this->publications, static fn (AtprotoPublication $publication): bool =>
			$publication->localUserId === $localUserId && $publication->postId === $postId);
		usort($matches, static fn (AtprotoPublication $a, AtprotoPublication $b): int => $a->accountDid <=> $b->accountDid);
		return array_values($matches);
	}

	public function insert(AtprotoPublication $publication): void
	{
		$key = $this->key($publication->localUserId, $publication->postId, $publication->accountDid);
		if (isset($this->publications[$key])) {
			throw new DatabaseException('The AT Protocol publication already exists.');
		}

		foreach ($this->publications as $stored) {
			if ($stored->accountDid === $publication->accountDid && $stored->rkey === $publication->rkey) {
				throw new DatabaseException('The AT Protocol record key is already used by this account.');
			}
		}

		$this->publications[$key] = $publication;
	}

	public function update(AtprotoPublication $publication): void
	{
		$key = $this->key($publication->localUserId, $publication->postId, $publication->accountDid);
		if (!isset($this->publications[$key])) {
			throw new DatabaseException('The AT Protocol publication does not exist.');
		}

		$this->publications[$key] = $publication;
	}

	public function removeForPost(string $localUserId, string $postId, ?string $accountDid = null): bool
	{
		if ($accountDid === null) { $accountDid = $this->findForPost($localUserId, $postId)?->accountDid; }
		if ($accountDid === null) { return false; }
		$key = $this->key($localUserId, $postId, $accountDid);
		if (!isset($this->publications[$key])) {
			return false;
		}

		unset($this->publications[$key]);

		return true;
	}

	private function key(string $localUserId, string $postId, string $accountDid): string
	{
		return implode("\0", [$localUserId, $accountDid, $postId]);
	}
}
