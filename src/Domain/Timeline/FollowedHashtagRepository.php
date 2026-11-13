<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use DateTimeImmutable;

/**
 * Storage of private followed hashtags.
 *
 * Every read and write is scoped to one local account. Following an already
 * followed tag is a successful no-op that keeps the original timestamp;
 * unfollowing an absent tag is a no-op as well.
 */
interface FollowedHashtagRepository
{
	/**
	 * @return bool whether a new row was inserted
	 */
	public function follow(string $accountId, string $tag, DateTimeImmutable $createdAt): bool;

	/**
	 * @return bool whether a followed row was removed
	 */
	public function unfollow(string $accountId, string $tag): bool;

	public function find(string $accountId, string $tag): ?FollowedHashtag;

	public function count(string $accountId): int;

	/**
	 * The account's followed tags, newest first with the tag as the
	 * tie-break. `$before` is exclusive; `$beforeTag` resolves equal
	 * timestamps.
	 *
	 * @return list<FollowedHashtag>
	 */
	public function page(
		string $accountId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeTag = null,
	): array;

	/**
	 * Every tag the account follows, newest first. The timeline uses this to
	 * build its exact-match filter. The caller enforces the follow limit, so
	 * this is a bounded list.
	 *
	 * @return list<string>
	 */
	public function tags(string $accountId): array;
}
