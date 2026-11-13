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
 * A remote actor that one local account follows.
 *
 * This is the outgoing edge of the follow graph. The actor id is the identity,
 * an absolute ActivityPub actor URL. The inbox is stored with it because a
 * Follow and its later Undo have to be delivered there; it is not used for
 * incoming delivery, which belongs to the follower graph.
 *
 * The state exists only on outgoing edges. Inbound followers are created by a
 * verified Follow and are therefore already accepted.
 */
final readonly class FollowedActor
{
	public function __construct(
		public string $id,
		public string $localUserId,
		public string $actorId,
		public string $inboxUrl,
		public DateTimeImmutable $followedAt,
		public FollowState $state = FollowState::Accepted,
		public ?string $followActivityId = null,
	) {
		if ($id === '') {
			throw new InvalidArgumentException('A followed actor needs an id.');
		}

		if ($localUserId === '') {
			throw new InvalidArgumentException('A followed actor needs a local user.');
		}

		if ($actorId === '') {
			throw new InvalidArgumentException('A followed actor needs an actor.');
		}

		if ($inboxUrl === '') {
			throw new InvalidArgumentException('A followed actor needs an inbox.');
		}
	}

	/**
	 * The same edge with a corrected inbox, keeping id and time.
	 */
	public function withInbox(string $inboxUrl): self
	{
		return new self(
			$this->id,
			$this->localUserId,
			$this->actorId,
			$inboxUrl,
			$this->followedAt,
			$this->state,
			$this->followActivityId,
		);
	}

	public function withState(FollowState $state): self
	{
		return new self(
			$this->id,
			$this->localUserId,
			$this->actorId,
			$this->inboxUrl,
			$this->followedAt,
			$state,
			$this->followActivityId,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'actor' => $this->actorId,
			'inbox' => $this->inboxUrl,
			'followed_at' => Time::format($this->followedAt),
			'state' => $this->state->value,
		];
	}
}
