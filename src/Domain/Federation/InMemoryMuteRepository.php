<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function strcmp;
use function usort;

/**
 * Private per-user account mutes in memory, for tests and the demo.
 *
 * Expiry is applied on every read that receives a time, so a short mute stops
 * suppressing content without a cleanup job. `find` deliberately returns the
 * stored row; the service decides whether it is still active.
 */
final class InMemoryMuteRepository implements MuteRepository
{
	/**
	 * @var array<string, Mute>
	 */
	private array $mutes = [];

	public function save(Mute $mute): void
	{
		$this->mutes[$this->key($mute->userId, $mute->actorId)] = $mute;
	}

	public function remove(string $userId, string $actorId): bool
	{
		$key = $this->key($userId, $actorId);
		if (!isset($this->mutes[$key])) {
			return false;
		}

		unset($this->mutes[$key]);

		return true;
	}

	public function find(string $userId, string $actorId): ?Mute
	{
		return $this->mutes[$this->key($userId, $actorId)] ?? null;
	}

	public function forUser(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeActorId = null,
		?DateTimeImmutable $after = null,
		?string $afterActorId = null,
		?DateTimeImmutable $now = null,
	): array {
		$mutes = array_values(array_filter(
			$this->mutes,
			static function (Mute $mute) use (
				$userId,
				$before,
				$beforeActorId,
				$after,
				$afterActorId,
				$now,
			): bool {
				if ($mute->userId !== $userId) {
					return false;
				}

				if ($now !== null && $mute->isExpired($now)) {
					return false;
				}

				if ($before !== null) {
					if ($mute->createdAt > $before) {
						return false;
					}

					if ($mute->createdAt == $before
						&& ($beforeActorId === null || strcmp($mute->actorId, $beforeActorId) >= 0)
					) {
						return false;
					}
				}

				if ($after !== null) {
					if ($mute->createdAt < $after) {
						return false;
					}

					if ($mute->createdAt == $after
						&& ($afterActorId === null || strcmp($mute->actorId, $afterActorId) <= 0)
					) {
						return false;
					}
				}

				return true;
			},
		));

		usort($mutes, static function (Mute $a, Mute $b): int {
			$byTime = $b->createdAt <=> $a->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($b->actorId, $a->actorId);
		});

		return array_slice($mutes, 0, max(1, $limit));
	}

	public function actorIds(string $userId, ?DateTimeImmutable $now = null): array
	{
		return array_map(
			static fn (Mute $mute): string => $mute->actorId,
			$this->forUser($userId, PHP_INT_MAX, null, null, null, null, $now),
		);
	}

	public function notificationActorIds(string $userId, ?DateTimeImmutable $now = null): array
	{
		return array_map(
			static fn (Mute $mute): string => $mute->actorId,
			array_values(array_filter(
				$this->forUser($userId, PHP_INT_MAX, null, null, null, null, $now),
				static fn (Mute $mute): bool => $mute->notifications,
			)),
		);
	}

	private function key(string $userId, string $actorId): string
	{
		return $userId . "\0" . $actorId;
	}
}
