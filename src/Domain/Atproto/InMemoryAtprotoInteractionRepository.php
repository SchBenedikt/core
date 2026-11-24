<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\DatabaseException;

final class InMemoryAtprotoInteractionRepository implements AtprotoInteractionRepository
{
	/** @var array<string, AtprotoInteractionRecord> */
	private array $records = [];

	public function find(string $localUserId, string $accountDid, string $postId, string $kind): ?AtprotoInteractionRecord
	{
		return $this->records[$this->key($localUserId, $accountDid, $postId, $kind)] ?? null;
	}

	public function insert(AtprotoInteractionRecord $record): void
	{
		$key = $this->key($record->localUserId, $record->accountDid, $record->postId, $record->kind);
		if (isset($this->records[$key])) {
			throw new DatabaseException('The AT Protocol interaction already exists.');
		}
		foreach ($this->records as $stored) {
			if ($stored->accountDid === $record->accountDid && $stored->rkey === $record->rkey) {
				throw new DatabaseException('The AT Protocol interaction record key is already in use.');
			}
		}
		$this->records[$key] = $record;
	}

	public function remove(string $localUserId, string $accountDid, string $postId, string $kind): bool
	{
		$key = $this->key($localUserId, $accountDid, $postId, $kind);
		if (!isset($this->records[$key])) {
			return false;
		}
		unset($this->records[$key]);

		return true;
	}

	public function forUser(string $localUserId): array
	{
		return array_values(array_filter(
			$this->records,
			static fn (AtprotoInteractionRecord $record): bool => $record->localUserId === $localUserId,
		));
	}

	private function key(string $localUserId, string $accountDid, string $postId, string $kind): string
	{
		return implode("\0", [$localUserId, $accountDid, $postId, $kind]);
	}
}
