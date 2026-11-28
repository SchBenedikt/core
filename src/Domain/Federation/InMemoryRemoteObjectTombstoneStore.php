<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

use function count;
use function uasort;
use function array_keys;

/**
 * The delete-before-create guard in memory, for tests and the reference
 * assembly.
 *
 * The same expiry and row cap as the SQL adapter; shared assertions in the
 * contract test are what keep the two interchangeable.
 */
final class InMemoryRemoteObjectTombstoneStore implements RemoteObjectTombstoneStore
{
	/**
	 * @var array<string, array{deleted_at: DateTimeImmutable, activity_id: string}>
	 */
	private array $tombstones = [];

	public function record(
		string $actorId,
		string $objectId,
		string $activityId,
		DateTimeImmutable $deletedAt,
	): void {
		$key = self::key($actorId, $objectId);
		if (isset($this->tombstones[$key])) {
			// The earliest deletion is the one that counts; a repeated Delete
			// must not extend the suppression window.
			return;
		}

		$this->tombstones[$key] = ['deleted_at' => $deletedAt, 'activity_id' => $activityId];
		$this->prune($deletedAt);
	}

	public function isDeleted(string $actorId, string $objectId, DateTimeImmutable $now): bool
	{
		$tombstone = $this->tombstones[self::key($actorId, $objectId)] ?? null;
		if ($tombstone === null) {
			return false;
		}

		return $tombstone['deleted_at']->getTimestamp() + RemoteObjectTombstoneStore::TTL_SECONDS
			>= $now->getTimestamp();
	}

	private static function key(string $actorId, string $objectId): string
	{
		return $actorId . "\x1F" . $objectId;
	}

	private function prune(DateTimeImmutable $now): void
	{
		$cutoff = $now->getTimestamp() - RemoteObjectTombstoneStore::TTL_SECONDS;
		foreach ($this->tombstones as $key => $tombstone) {
			if ($tombstone['deleted_at']->getTimestamp() < $cutoff) {
				unset($this->tombstones[$key]);
			}
		}

		if (count($this->tombstones) <= RemoteObjectTombstoneStore::MAX_ENTRIES) {
			return;
		}

		uasort(
			$this->tombstones,
			static fn (array $a, array $b): int => $a['deleted_at'] <=> $b['deleted_at'],
		);

		$excess = count($this->tombstones) - RemoteObjectTombstoneStore::MAX_ENTRIES;
		foreach (array_keys($this->tombstones) as $key) {
			if ($excess <= 0) {
				break;
			}

			unset($this->tombstones[$key]);
			$excess--;
		}
	}
}
