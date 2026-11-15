<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;

final class InMemoryPostPinRepository implements PostPinRepository
{
	/**
	 * @var array<string, PostPin>
	 */
	private array $pins = [];

	public function insert(PostPin $pin): void
	{
		$key = $this->key($pin->ownerId, $pin->postId);
		if (isset($this->pins[$key])) {
			throw new DatabaseException('The post is already pinned.');
		}

		foreach ($this->pins as $stored) {
			if ($stored->ownerId === $pin->ownerId && $stored->position === $pin->position) {
				throw new DatabaseException('The pin position is already taken.');
			}
		}

		$this->pins[$key] = $pin;
	}

	public function find(string $ownerId, string $postId): ?PostPin
	{
		return $this->pins[$this->key($ownerId, $postId)] ?? null;
	}

	public function forOwner(string $ownerId): array
	{
		$pins = array_values(array_filter(
			$this->pins,
			static fn (PostPin $pin): bool => $pin->ownerId === $ownerId,
		));
		usort($pins, static fn (PostPin $a, PostPin $b): int => $a->position <=> $b->position);

		return $pins;
	}

	public function count(string $ownerId): int
	{
		return count($this->forOwner($ownerId));
	}

	public function remove(string $ownerId, string $postId): bool
	{
		$key = $this->key($ownerId, $postId);
		if (!isset($this->pins[$key])) {
			return false;
		}

		unset($this->pins[$key]);

		return true;
	}

	public function removeForPost(string $postId): void
	{
		foreach ($this->pins as $key => $pin) {
			if ($pin->postId === $postId) {
				unset($this->pins[$key]);
			}
		}
	}

	private function key(string $ownerId, string $postId): string
	{
		return $ownerId . "\0" . $postId;
	}
}
