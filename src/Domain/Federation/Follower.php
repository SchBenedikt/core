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
 * An actor that follows one local account.
 *
 * The actor id is the identity — a follower arrives as an absolute
 * ActivityPub actor URL, never as a local user name. The inbox is stored
 * alongside it so remote delivery needs no network round trip at post time;
 * local-to-local edges carry the local shared inbox but are excluded from
 * delivery resolution.
 */
final readonly class Follower
{
	public function __construct(
		public string $id,
		public string $localUserId,
		public string $actorId,
		public string $inboxUrl,
		public DateTimeImmutable $followedAt,
		public ?string $sharedInboxUrl = null,
		/** True when this is a local-to-local edge, not a remote inbox. */
		public bool $local = false,
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

		if ($sharedInboxUrl !== null && $sharedInboxUrl === '') {
			throw new InvalidArgumentException('A follower shared inbox must be an absolute URL when present.');
		}
	}

	/**
	 * The same follower with a corrected inbox.
	 *
	 * An already accepted follower may move its inbox; its id and the time it
	 * followed must not change, because clients and logs refer to them.
	 */
	public function withInbox(string $inboxUrl, ?string $sharedInboxUrl = null): self
	{
		return new self(
			$this->id,
			$this->localUserId,
			$this->actorId,
			$inboxUrl,
			$this->followedAt,
			$sharedInboxUrl ?? $this->sharedInboxUrl,
			$this->local,
		);
	}

	/**
	 * The inbox to use for content delivery.
	 *
	 * A server's shared inbox covers every follower on that server, so it is
	 * preferred over the per-actor inbox for content. Activity-specific
	 * delivery such as Accept still uses the individual inbox because the
	 * peer matches it to one actor.
	 */
	public function deliveryInboxUrl(): string
	{
		return $this->sharedInboxUrl ?? $this->inboxUrl;
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
