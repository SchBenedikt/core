<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/** Optional keyset paging for pending followers with a stable ID tie-break. */
interface PendingFollowerCursorRepository
{
	/**
	 * Pending requests in descending (followed_at, id) order.
	 *
	 * @return list<Follower>
	 */
	public function pendingBetween(
		string $localUserId,
		int $limit,
		?DateTimeImmutable $before,
		?string $beforeId,
		?DateTimeImmutable $after,
		?string $afterId,
	): array;
}
