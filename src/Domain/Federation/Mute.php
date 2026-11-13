<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;

/**
 * One local account's mute of a local account or remote actor.
 *
 * A mute is private to its owner and suppresses the target from the owner's
 * home timeline. `notifications` additionally suppresses events caused by the
 * target. The pair (user, actor) is the identity, so a repeat updates the
 * settings instead of creating a second row.
 */
final readonly class Mute
{
	public const MAX_USER_LENGTH = 255;

	public const MAX_ACTOR_LENGTH = 512;

	public function __construct(
		public string $userId,
		public string $actorId,
		public bool $notifications,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $expiresAt = null,
	) {
		if ($userId === '' || mb_strlen($userId) > self::MAX_USER_LENGTH) {
			throw new InvalidArgumentException('A mute needs a user id of at most 255 characters.');
		}

		if ($actorId === '' || mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			throw new InvalidArgumentException('A mute needs an actor id of at most 512 characters.');
		}
	}

	public function isExpired(DateTimeImmutable $now): bool
	{
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}
}
