<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;

/**
 * Optional bounded snapshot cache. A missing or expired snapshot is not
 * silently replaced: the service rejects the cursor instead of replaying a
 * different feed.
 */
interface RecommendationSnapshotCache
{
	public function put(RecommendationSnapshot $snapshot): void;

	public function get(string $id, DateTimeImmutable $now): ?RecommendationSnapshot;
}
