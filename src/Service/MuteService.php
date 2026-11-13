<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Private account mutes and their optional expiry.
 *
 * A mute always hides the target from the owner's home timeline. The separate
 * `notifications` setting only controls whether events caused by the target
 * are hidden from the notification list and unread count. Expired rows remain
 * stored, are ignored by reads and can be pruned by the host later.
 */
final class MuteService
{
	public const MAX_DURATION_SECONDS = 31_536_000;

	public function __construct(
		private readonly MuteRepository $mutes,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Creates or updates a mute.
	 *
	 * A null duration means indefinite. Repeating the call with different
	 * settings updates the same row, and a new duration restarts the expiry
	 * from the current request time.
	 */
	public function mute(
		string $userId,
		string $actorId,
		bool $notifications = false,
		?int $durationSeconds = null,
	): Mute {
		if ($actorId === '') {
			throw new InvalidArgumentException('A mute needs an actor id.');
		}

		if ($durationSeconds !== null
			&& ($durationSeconds < 1 || $durationSeconds > self::MAX_DURATION_SECONDS)
		) {
			throw new InvalidArgumentException('A mute duration is outside the supported range.');
		}

		$now = $this->now();
		$mute = new Mute(
			userId: $userId,
			actorId: $actorId,
			notifications: $notifications,
			createdAt: $now,
			expiresAt: $durationSeconds === null
				? null
				: $now->modify('+' . $durationSeconds . ' seconds'),
		);

		$this->mutes->save($mute);

		return $mute;
	}

	public function unmute(string $userId, string $actorId): bool
	{
		return $this->mutes->remove($userId, $actorId);
	}

	/**
	 * The active mute for this pair, or null when absent or expired.
	 */
	public function find(string $userId, string $actorId): ?Mute
	{
		$mute = $this->mutes->find($userId, $actorId);

		return $mute === null || $mute->isExpired($this->now()) ? null : $mute;
	}

	public function isMuted(string $userId, string $actorId): bool
	{
		return $this->find($userId, $actorId) !== null;
	}

	/**
	 * @return list<Mute>
	 */
	public function forUser(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeActorId = null,
		?DateTimeImmutable $after = null,
		?string $afterActorId = null,
	): array {
		return $this->mutes->forUser(
			$userId,
			$limit,
			$before,
			$beforeActorId,
			$after,
			$afterActorId,
			$this->now(),
		);
	}

	/**
	 * Active target ids for home-timeline filtering.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $userId): array
	{
		return $this->mutes->actorIds($userId, $this->now());
	}

	/**
	 * Active target ids whose notifications are muted.
	 *
	 * @return list<string>
	 */
	public function notificationActorIds(string $userId): array
	{
		return $this->mutes->notificationActorIds($userId, $this->now());
	}

	private function now(): DateTimeImmutable
	{
		return Time::toSecondPrecision($this->clock->now());
	}
}
