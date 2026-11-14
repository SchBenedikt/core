<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * The early-update buffer in memory, for tests and the reference assembly.
 *
 * The same expiry and row cap as the SQL adapter; the shared contract test is
 * what keeps the two interchangeable.
 */
final class InMemoryPendingRemoteUpdateStore implements PendingRemoteUpdateStore
{
	/**
	 * @var array<string, PendingRemoteUpdate>
	 */
	private array $updates = [];

	public function store(PendingRemoteUpdate $update): void
	{
		$this->updates[$update->objectId] = $update;
		$this->prune($update->receivedAt);
	}

	public function take(string $objectId, string $actorId, DateTimeImmutable $now): ?PendingRemoteUpdate
	{
		$update = $this->updates[$objectId] ?? null;
		if ($update === null) {
			return null;
		}

		unset($this->updates[$objectId]);

		if ($update->actorId !== $actorId) {
			return null;
		}

		if ($update->receivedAt->getTimestamp() + PendingRemoteUpdateStore::TTL_SECONDS < $now->getTimestamp()) {
			return null;
		}

		return $update;
	}

	private function prune(DateTimeImmutable $now): void
	{
		$cutoff = $now->getTimestamp() - PendingRemoteUpdateStore::TTL_SECONDS;
		foreach ($this->updates as $objectId => $update) {
			if ($update->receivedAt->getTimestamp() < $cutoff) {
				unset($this->updates[$objectId]);
			}
		}

		if (count($this->updates) <= PendingRemoteUpdateStore::MAX_ENTRIES) {
			return;
		}

		uasort(
			$this->updates,
			static fn (PendingRemoteUpdate $a, PendingRemoteUpdate $b): int => $a->receivedAt <=> $b->receivedAt,
		);

		$excess = count($this->updates) - PendingRemoteUpdateStore::MAX_ENTRIES;
		foreach (array_keys($this->updates) as $objectId) {
			if ($excess <= 0) {
				break;
			}

			unset($this->updates[$objectId]);
			$excess--;
		}
	}
}
