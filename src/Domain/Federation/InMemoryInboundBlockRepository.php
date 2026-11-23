<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

final class InMemoryInboundBlockRepository implements InboundBlockRepository
{
	/** @var array<string, true> */
	private array $blocks = [];

	public function block(string $localUserId, string $actorId): void
	{
		$this->blocks[$this->key($localUserId, $actorId)] = true;
	}

	public function unblock(string $localUserId, string $actorId): void
	{
		unset($this->blocks[$this->key($localUserId, $actorId)]);
	}

	public function isBlocked(string $localUserId, string $actorId): bool
	{
		return isset($this->blocks[$this->key($localUserId, $actorId)]);
	}

	private function key(string $localUserId, string $actorId): string
	{
		return $localUserId . "\0" . $actorId;
	}
}
