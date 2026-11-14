<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * The follow graph in memory, for tests, the demo and tools.
 *
 * It mirrors the SQL implementation's ordering, cursor and uniqueness rules,
 * so the two cannot drift without a contract test failing.
 */
final class InMemoryFollowerRepository implements FollowerRepository
{
	/**
	 * @var array<string, Follower>
	 */
	private array $followers = [];

	public function insert(Follower $follower): void
	{
		$this->followers[$follower->id] = $follower;
	}

	public function updateInbox(Follower $follower): void
	{
		if (isset($this->followers[$follower->id])) {
			$this->followers[$follower->id] = $follower;
		}
	}

	public function find(string $id): ?Follower
	{
		return $this->followers[$id] ?? null;
	}

	public function findByActor(string $localUserId, string $actorId): ?Follower
	{
		foreach ($this->followers as $follower) {
			if ($follower->localUserId === $localUserId && $follower->actorId === $actorId) {
				return $follower;
			}
		}

		return null;
	}

	public function remove(string $id, string $localUserId): bool
	{
		$follower = $this->followers[$id] ?? null;
		if ($follower === null || $follower->localUserId !== $localUserId) {
			return false;
		}

		unset($this->followers[$id]);

		return true;
	}

	public function updateState(
		string $id,
		string $localUserId,
		FollowState $from,
		FollowState $to,
	): bool {
		$follower = $this->followers[$id] ?? null;
		if ($follower === null || $follower->localUserId !== $localUserId || $follower->state !== $from) {
			return false;
		}

		// A rejected request is not an edge and must not linger in the
		// follower graph. The peer's next Follow is a fresh request.
		if ($to === FollowState::Rejected) {
			unset($this->followers[$id]);

			return true;
		}

		$this->followers[$id] = $follower->withState($to);

		return true;
	}

	public function pending(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$matching = array_values(array_filter(
			$this->followers,
			static fn (Follower $follower): bool => $follower->localUserId === $localUserId
				&& $follower->state === FollowState::Pending
				&& ($before === null || $follower->followedAt < $before),
		));

		usort($matching, static function (Follower $a, Follower $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($matching, 0, max(1, $limit));
	}

	public function removeByActor(string $localUserId, string $actorId): bool
	{
		foreach ($this->followers as $id => $follower) {
			if ($follower->localUserId === $localUserId && $follower->actorId === $actorId) {
				unset($this->followers[$id]);

				return true;
			}
		}

		return false;
	}

	public function followers(string $localUserId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$matching = array_values(array_filter(
			$this->followers,
			static fn (Follower $follower): bool => $follower->localUserId === $localUserId
				&& $follower->state === FollowState::Accepted
				&& ($before === null || $follower->followedAt < $before),
		));

		usort($matching, static function (Follower $a, Follower $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($matching, 0, max(1, $limit));
	}

	public function actorIds(string $localUserId): array
	{
		$matching = array_values(array_filter(
			$this->followers,
			static fn (Follower $follower): bool => $follower->localUserId === $localUserId
				&& $follower->state === FollowState::Accepted,
		));

		usort($matching, static function (Follower $a, Follower $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_map(static fn (Follower $follower): string => $follower->actorId, $matching);
	}

	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		return array_slice($this->actorIds($localUserId), max(0, $offset), max(0, $limit));
	}

	public function localFollowsByActor(string $actorId): array
	{
		$matching = array_values(array_filter(
			$this->followers,
			static fn (Follower $follower): bool => $follower->local
				&& $follower->actorId === $actorId
				&& $follower->state === FollowState::Accepted,
		));

		usort($matching, static function (Follower $a, Follower $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $matching;
	}

	public function localUserIdsFollowedBy(string $actorId): array
	{
		$users = [];
		foreach ($this->followers as $follower) {
			if ($follower->local
				&& $follower->actorId === $actorId
				&& $follower->state === FollowState::Accepted
			) {
				$users[$follower->localUserId] = true;
			}
		}

		$result = array_keys($users);
		sort($result, SORT_STRING);

		return $result;
	}

	public function localFollowingCount(string $actorId): int
	{
		$count = 0;
		foreach ($this->followers as $follower) {
			if ($follower->local
				&& $follower->actorId === $actorId
				&& $follower->state === FollowState::Accepted
			) {
				$count++;
			}
		}

		return $count;
	}

	public function inboxesFor(string $localUserId): array
	{
		$inboxes = [];
		foreach ($this->followers as $follower) {
			if ($follower->localUserId === $localUserId
				&& !$follower->local
				&& $follower->state === FollowState::Accepted
			) {
				$inboxes[$follower->deliveryInboxUrl()] = true;
			}
		}

		$result = array_keys($inboxes);
		sort($result, SORT_STRING);

		return $result;
	}

	public function followersForActor(string $actorId, int $limit, int $offset): array
	{
		$matching = array_values(array_filter(
			$this->followers,
			static fn (Follower $follower): bool => $follower->actorId === $actorId
				&& ($follower->state === FollowState::Accepted || $follower->state === FollowState::Pending),
		));

		usort($matching, static function (Follower $a, Follower $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($matching, max(0, $offset), max(0, $limit));
	}

	public function replaceActor(
		string $id,
		string $localUserId,
		string $oldActorId,
		string $newActorId,
		string $inboxUrl,
		?string $sharedInboxUrl,
	): bool {
		$stored = $this->followers[$id] ?? null;
		if ($stored === null
			|| $stored->localUserId !== $localUserId
			|| $stored->actorId !== $oldActorId
		) {
			return false;
		}

		$this->followers[$id] = new Follower(
			id: $stored->id,
			localUserId: $stored->localUserId,
			actorId: $newActorId,
			inboxUrl: $inboxUrl,
			followedAt: $stored->followedAt,
			sharedInboxUrl: $sharedInboxUrl,
			local: false,
			state: $stored->state,
		);

		return true;
	}

	public function count(string $localUserId): int
	{
		$count = 0;
		foreach ($this->followers as $follower) {
			if ($follower->localUserId === $localUserId && $follower->state === FollowState::Accepted) {
				$count++;
			}
		}

		return $count;
	}
}
