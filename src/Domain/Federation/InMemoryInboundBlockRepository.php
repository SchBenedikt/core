<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

final class InMemoryInboundBlockRepository implements InboundBlockRepository
{
	/** @var array<string, array{actor_id: string, activity_id: string}> */
	private array $blocks = [];

	/** @var array<string, string> activity id to identity key */
	private array $activityKeys = [];

	public function block(string $localUserId, string $actorId, string $activityId): void
	{
		$key = $this->key($localUserId, $actorId);
		$existing = $this->blocks[$key] ?? null;
		if ($existing !== null) {
			// Match the PDO repository: duplicate or newer Block deliveries must
			// not change which actor-owned activity can undo this relationship.
			return;
		}
		$this->blocks[$key] = ['actor_id' => $actorId, 'activity_id' => $activityId];
		$this->activityKeys[$this->activityKey($actorId, $activityId)] = $key;
	}

	public function unblockByActivityId(string $actorId, string $activityId, ?string $localUserId = null): bool
	{
		$key = $this->activityKeys[$this->activityKey($actorId, $activityId)] ?? null;
		if ($key === null
			|| ($this->blocks[$key]['actor_id'] ?? null) !== $actorId
			|| ($localUserId !== null && $key !== $this->key($localUserId, $actorId))
		) {
			return false;
		}
		unset($this->blocks[$key], $this->activityKeys[$this->activityKey($actorId, $activityId)]);

		return true;
	}

	public function isBlocked(string $localUserId, string $actorId): bool
	{
		return isset($this->blocks[$this->key($localUserId, $actorId)]);
	}

	private function key(string $localUserId, string $actorId): string
	{
		return $localUserId . "\0" . $actorId;
	}

	private function activityKey(string $actorId, string $activityId): string
	{
		return $actorId . "\0" . $activityId;
	}
}
