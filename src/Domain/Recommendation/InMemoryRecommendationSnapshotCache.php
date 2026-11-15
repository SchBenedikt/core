<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;

/**
 * Process-local snapshot cache. Production hosts can inject a shared cache;
 * without one, a cursor is valid only on the worker that created it and is
 * rejected rather than silently restarted.
 */
final class InMemoryRecommendationSnapshotCache implements RecommendationSnapshotCache
{
	/** @var array<string, RecommendationSnapshot> */
	private array $snapshots = [];

	public function put(RecommendationSnapshot $snapshot): void
	{
		$this->snapshots[$snapshot->id] = $snapshot;
	}

	public function get(string $id, DateTimeImmutable $now): ?RecommendationSnapshot
	{
		$snapshot = $this->snapshots[$id] ?? null;
		if ($snapshot === null) {
			return null;
		}

		if ($snapshot->expiredAt($now)) {
			unset($this->snapshots[$id]);

			return null;
		}

		return $snapshot;
	}
}
