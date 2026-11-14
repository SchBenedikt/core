<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

final class InMemoryRemotePollRepository implements RemotePollRepository
{
	/**
	 * @var array<string, RemotePoll>
	 */
	private array $polls = [];

	public function upsert(RemotePoll $poll): void
	{
		$existing = $this->polls[$poll->objectId] ?? null;

		// A peer can close a poll only through its own Update; a local close
		// marker must not be undone by a stale retransmission.
		if ($existing !== null && $existing->closedAt !== null && $poll->closedAt === null) {
			$poll = new RemotePoll(
				objectId: $poll->objectId,
				actorId: $poll->actorId,
				activityId: $poll->activityId,
				title: $poll->title,
				options: $poll->options,
				multiple: $poll->multiple,
				endsAt: $poll->endsAt,
				closedAt: $existing->closedAt,
				votersCount: $poll->votersCount,
				updatedAt: $poll->updatedAt,
			);
		}

		$this->polls[$poll->objectId] = $poll;
	}

	public function find(string $objectId): ?RemotePoll
	{
		return $this->polls[$objectId] ?? null;
	}

	public function markClosed(string $objectId, DateTimeImmutable $closedAt): bool
	{
		$poll = $this->polls[$objectId] ?? null;
		if ($poll === null || $poll->closedAt !== null) {
			return false;
		}

		$this->polls[$objectId] = new RemotePoll(
			objectId: $poll->objectId,
			actorId: $poll->actorId,
			activityId: $poll->activityId,
			title: $poll->title,
			options: $poll->options,
			multiple: $poll->multiple,
			endsAt: $poll->endsAt,
			closedAt: $closedAt,
			votersCount: $poll->votersCount,
			updatedAt: $poll->updatedAt,
		);

		return true;
	}
}
