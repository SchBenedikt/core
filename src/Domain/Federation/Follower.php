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
 * A remote actor that follows one local account.
 *
 * The actor id is the identity — a follower always arrives as an absolute
 * ActivityPub actor URL, never as a local user name. The inbox is stored
 * alongside it so delivery needs no network round trip at post time.
 */
final readonly class Follower
{
	public function __construct(
		public string $id,
		public string $localUserId,
		public string $actorId,
		public string $inboxUrl,
		public DateTimeImmutable $followedAt,
	) {
		if ($id === '') {
			throw new InvalidArgumentException('A follower needs an id.');
		}

		if ($localUserId === '') {
			throw new InvalidArgumentException('A follower needs a local user.');
		}

		if ($actorId === '') {
			throw new InvalidArgumentException('A follower needs an actor.');
		}

		if ($inboxUrl === '') {
			throw new InvalidArgumentException('A follower needs an inbox.');
		}
	}

	/**
	 * The same follower with a corrected inbox.
	 *
	 * An already accepted follower may move its inbox; its id and the time it
	 * followed must not change, because clients and logs refer to them.
	 */
	public function withInbox(string $inboxUrl): self
	{
		return new self($this->id, $this->localUserId, $this->actorId, $inboxUrl, $this->followedAt);
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
			'inbox' => $this->inboxUrl,
			'followed_at' => Time::format($this->followedAt),
		];
	}
}
