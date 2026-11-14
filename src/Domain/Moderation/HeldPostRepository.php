<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use DateTimeImmutable;

/**
 * Durable queue of posts awaiting approval.
 *
 * `claim` is the concurrency point: only one reviewer can move a pending row
 * to reviewing, so only one approval can call PostService for it.
 */
interface HeldPostRepository
{
	public function insert(HeldPost $post): void;

	public function find(string $id): ?HeldPost;

	/**
	 * Oldest pending posts first.
	 *
	 * @return list<HeldPost>
	 */
	public function queue(int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * pending -> reviewing. False when another reviewer won.
	 */
	public function claim(string $id, string $moderatorId, DateTimeImmutable $at): bool;

	public function approve(
		string $id,
		string $postId,
		string $moderatorId,
		?string $note,
		DateTimeImmutable $at,
	): bool;

	public function reject(
		string $id,
		string $moderatorId,
		string $note,
		DateTimeImmutable $at,
	): bool;

	/**
	 * reviewing -> pending after a failed publication.
	 */
	public function release(string $id): void;
}
