<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\DatabaseException;

/**
 * The outgoing follow graph in memory, for tests, the demo and tools.
 *
 * It mirrors the SQL implementation's ordering, uniqueness and ownership
 * rules, so the two cannot drift without a contract test failing.
 */
final class InMemoryFollowingRepository implements FollowingRepository
{
	/**
	 * @var array<string, FollowedActor>
	 */
	private array $following = [];

	public function insert(FollowedActor $actor): void
	{
		if (isset($this->following[$actor->id])) {
			throw new DatabaseException('The followed actor id is already stored.');
		}

		if ($this->find($actor->localUserId, $actor->actorId) !== null) {
			throw new DatabaseException('This local user already follows the actor.');
		}

		$this->following[$actor->id] = $actor;
	}

	public function find(string $localUserId, string $actorId): ?FollowedActor
	{
		foreach ($this->following as $followed) {
			if ($followed->localUserId === $localUserId && $followed->actorId === $actorId) {
				return $followed;
			}
		}

		return null;
	}

	public function findById(string $id): ?FollowedActor
	{
		return $this->following[$id] ?? null;
	}

	public function findByActivityId(string $activityId): ?FollowedActor
	{
		foreach ($this->following as $followed) {
			if ($followed->followActivityId === $activityId) {
				return $followed;
			}
		}

		return null;
	}

	public function updateInbox(FollowedActor $actor): void
	{
		if (isset($this->following[$actor->id])) {
			$this->following[$actor->id] = $actor;
		}
	}

	public function updateState(string $id, FollowState $expected, FollowState $state): bool
	{
		$stored = $this->following[$id] ?? null;
		if ($stored === null || $stored->state !== $expected) {
			return false;
		}

		$this->following[$id] = $stored->withState($state);

		return true;
	}

	public function remove(string $localUserId, string $actorId): bool
	{
		foreach ($this->following as $id => $followed) {
			if ($followed->localUserId === $localUserId && $followed->actorId === $actorId) {
				unset($this->following[$id]);

				return true;
			}
		}

		return false;
	}

	public function actorIds(string $localUserId): array
	{
		$matching = array_values(array_filter(
			$this->following,
			static fn (FollowedActor $followed): bool => $followed->localUserId === $localUserId
				&& $followed->state->isAccepted(),
		));

		usort($matching, static function (FollowedActor $a, FollowedActor $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_map(static fn (FollowedActor $followed): string => $followed->actorId, $matching);
	}

	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		return array_slice($this->actorIds($localUserId), max(0, $offset), max(0, $limit));
	}

	public function followedActors(string $localUserId): array
	{
		$matching = array_values(array_filter(
			$this->following,
			static fn (FollowedActor $followed): bool => $followed->localUserId === $localUserId
				&& $followed->state->isAccepted(),
		));

		usort($matching, static function (FollowedActor $a, FollowedActor $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $matching;
	}

	public function count(string $localUserId): int
	{
		$count = 0;
		foreach ($this->following as $followed) {
			if ($followed->localUserId === $localUserId && $followed->state->isAccepted()) {
				$count++;
			}
		}

		return $count;
	}

	public function followedActorsForActor(string $actorId, int $limit, int $offset): array
	{
		$matching = array_values(array_filter(
			$this->following,
			static fn (FollowedActor $followed): bool => $followed->actorId === $actorId
				&& ($followed->state->isAccepted() || $followed->state === FollowState::Pending),
		));

		usort($matching, static function (FollowedActor $a, FollowedActor $b): int {
			$byTime = $b->followedAt <=> $a->followedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($matching, max(0, $offset), max(0, $limit));
	}

	public function localUserIdsForActor(string $actorId): array
	{
		$users = [];
		foreach ($this->following as $followed) {
			if ($followed->actorId === $actorId && $followed->state->isAccepted()) {
				$users[$followed->localUserId] = true;
			}
		}

		$result = array_keys($users);
		sort($result, SORT_STRING);

		return $result;
	}
}
