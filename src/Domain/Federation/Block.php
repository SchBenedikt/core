<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A remote actor blocked by one local account.
 *
 * A block is private to the local account that created it. It hides the
 * actor's remote posts and boosts from that account's home timeline without
 * affecting another local account that still follows the same actor.
 */
final readonly class Block
{
	public const MAX_ACTOR_LENGTH = 255;

	public function __construct(
		public string $id,
		public string $localUserId,
		public string $actorId,
		public DateTimeImmutable $blockedAt,
	) {
		if ($id === '') {
			throw new InvalidArgumentException('A block needs an id.');
		}

		if ($localUserId === '') {
			throw new InvalidArgumentException('A block needs a local user.');
		}

		if ($actorId === '') {
			throw new InvalidArgumentException('A block needs an actor.');
		}

		if (mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			throw new InvalidArgumentException('A blocked actor id is too long.');
		}
	}

	/**
	 * The shape the API returns.
	 *
	 * `local_user_id` is deliberately absent: it is always the authenticated
	 * caller, and echoing it back would only invite a client to trust it.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'actor' => $this->actorId,
			'blocked_at' => Time::format($this->blockedAt),
		];
	}
}
