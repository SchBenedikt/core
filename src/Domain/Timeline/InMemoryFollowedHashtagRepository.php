<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use DateTimeImmutable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function strcmp;
use function usort;

/**
 * Followed hashtags in memory.
 *
 * The key mirrors the SQL primary key so the two repositories share the same
 * idempotency and ordering semantics.
 */
final class InMemoryFollowedHashtagRepository implements FollowedHashtagRepository
{
	/**
	 * @var array<string, FollowedHashtag>
	 */
	private array $follows = [];

	public function follow(string $accountId, string $tag, DateTimeImmutable $createdAt): bool
	{
		$key = $this->key($accountId, $tag);
		if (isset($this->follows[$key])) {
			return false;
		}

		$this->follows[$key] = new FollowedHashtag($accountId, $tag, $createdAt);

		return true;
	}

	public function unfollow(string $accountId, string $tag): bool
	{
		$key = $this->key($accountId, $tag);
		if (!isset($this->follows[$key])) {
			return false;
		}

		unset($this->follows[$key]);

		return true;
	}

	public function find(string $accountId, string $tag): ?FollowedHashtag
	{
		return $this->follows[$this->key($accountId, $tag)] ?? null;
	}

	public function page(
		string $accountId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeTag = null,
	): array {
		$follows = array_values(array_filter(
			$this->follows,
			static function (FollowedHashtag $follow) use ($accountId, $before, $beforeTag): bool {
				if ($follow->accountId !== $accountId) {
					return false;
				}

				if ($before === null) {
					return true;
				}

				$comparison = $follow->createdAt <=> $before;
				if ($comparison < 0) {
					return true;
				}

				return $comparison === 0
					&& $beforeTag !== null
					&& strcmp($follow->tag, $beforeTag) < 0;
			},
		));

		usort(
			$follows,
			static fn (FollowedHashtag $left, FollowedHashtag $right): int => ($right->createdAt <=> $left->createdAt)
				?: strcmp($right->tag, $left->tag),
		);

		return array_slice($follows, 0, max(0, $limit));
	}

	public function count(string $accountId): int
	{
		return count(array_filter(
			$this->follows,
			static fn (FollowedHashtag $follow): bool => $follow->accountId === $accountId,
		));
	}

	public function tags(string $accountId): array
	{
		return array_map(
			static fn (FollowedHashtag $follow): string => $follow->tag,
			$this->page($accountId, PHP_INT_MAX),
		);
	}

	private function key(string $accountId, string $tag): string
	{
		return $accountId . "\0" . $tag;
	}
}
