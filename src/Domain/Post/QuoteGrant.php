<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A FEP-044f authorization to quote one object from one interacting object.
 *
 * A grant is the durable answer to a QuoteRequest (or the synchronous grant
 * for two local posts). It names the quoted object, the quoting object and the
 * actor that asked; the id is stable, so the authorization document a peer
 * received can always be resolved and later revoked.
 */
final readonly class QuoteGrant
{
	public const MAX_URL_LENGTH = 1024;

	public const MAX_ACTOR_LENGTH = 255;

	public function __construct(
		public string $id,
		public string $quotedObjectId,
		public string $interactingObjectId,
		public string $actorId,
		public string $quotedActorId,
		public QuoteState $state,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $updatedAt,
	) {
		foreach ([$this->id, $this->quotedObjectId, $this->interactingObjectId] as $value) {
			if ($value === '') {
				throw new InvalidArgumentException('A quote grant needs an id, a quoted object and an interacting object.');
			}
		}

		if (mb_strlen($this->id) > 64
			|| mb_strlen($this->quotedObjectId) > self::MAX_URL_LENGTH
			|| mb_strlen($this->interactingObjectId) > self::MAX_URL_LENGTH
		) {
			throw new InvalidArgumentException('A quote grant id or object URL is too long.');
		}

		if ($this->actorId === '' || mb_strlen($this->actorId) > self::MAX_ACTOR_LENGTH
			|| $this->quotedActorId === '' || mb_strlen($this->quotedActorId) > self::MAX_ACTOR_LENGTH
		) {
			throw new InvalidArgumentException('A quote grant needs two bounded actor ids.');
		}

		if ($this->state === QuoteState::Pending) {
			throw new InvalidArgumentException('A stored quote grant is never pending.');
		}
	}

	public function isActive(): bool
	{
		return $this->state->isAccepted();
	}

	public function withState(QuoteState $state, DateTimeImmutable $at): self
	{
		return new self(
			id: $this->id,
			quotedObjectId: $this->quotedObjectId,
			interactingObjectId: $this->interactingObjectId,
			actorId: $this->actorId,
			quotedActorId: $this->quotedActorId,
			state: $state,
			createdAt: $this->createdAt,
			updatedAt: Time::toSecondPrecision($at),
		);
	}
}
