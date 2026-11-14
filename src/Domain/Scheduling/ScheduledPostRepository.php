<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use DateTimeImmutable;

/**
 * Storage of scheduled post writes.
 *
 * The publication claim is a compare-and-set in SQL and a single guarded
 * write in memory: two workers that ask for the same due row must not both
 * receive it. A claim also carries a lease, so a crashed worker's row becomes
 * due again instead of staying claimed forever.
 */
interface ScheduledPostRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id or the reserved post id is taken
	 */
	public function insert(ScheduledPost $post): void;

	public function find(string $id): ?ScheduledPost;

	/**
	 * Newest first, with the id as the tie-breaker so paging is stable.
	 *
	 * @return list<ScheduledPost>
	 */
	public function forAuthor(
		string $authorId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array;

	/**
	 * How many schedules the author created at or after `$since`, including
	 * cancelled ones: a quota limits the work a caller can ask for, not the
	 * rows that happen to survive.
	 */
	public function countCreatedSince(string $authorId, DateTimeImmutable $since): int;

	/**
	 * Due rows a worker may try to claim, oldest first.
	 *
	 * A row is due when it is `scheduled` and its time has come, or when it is
	 * `publishing` and its lease has expired.
	 *
	 * @return list<string>
	 */
	public function dueIds(DateTimeImmutable $now, int $limit): array;

	/**
	 * Atomically moves a due row to `publishing` with a lease. False means
	 * another worker won, the row is no longer pending or it is not due.
	 */
	public function claim(string $id, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool;

	/**
	 * Returns a claimed row to `scheduled` without touching its time.
	 */
	public function release(string $id): void;

	public function markPublished(string $id, DateTimeImmutable $publishedAt): void;

	public function markFailed(string $id, string $reason, DateTimeImmutable $failedAt): void;

	/**
	 * Moves a pending row to a new time. False when it is no longer pending.
	 */
	public function reschedule(string $id, DateTimeImmutable $scheduledAt): bool;

	/**
	 * Cancels a pending row. False when it is no longer pending, which is how
	 * a publication that already started wins the race.
	 */
	public function cancel(string $id): bool;
}
