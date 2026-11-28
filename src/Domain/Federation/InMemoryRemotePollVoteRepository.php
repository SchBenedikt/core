<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

final class InMemoryRemotePollVoteRepository implements RemotePollVoteRepository
{
	/**
	 * @var array<string, RemotePollVote>
	 */
	private array $votes = [];

	public function find(string $objectId, string $userId): ?RemotePollVote
	{
		return $this->votes[$objectId . "\n" . $userId] ?? null;
	}

	public function insert(RemotePollVote $vote): void
	{
		$key = $vote->objectId . "\n" . $vote->userId;
		if (isset($this->votes[$key])) {
			throw new DatabaseException('The account already voted in this poll.');
		}

		$this->votes[$key] = $vote;
	}

	public function markQueued(string $objectId, string $userId): void
	{
		$key = $objectId . "\n" . $userId;
		$vote = $this->votes[$key] ?? null;
		if ($vote === null || $vote->queued) {
			return;
		}

		$this->votes[$key] = new RemotePollVote(
			$vote->objectId,
			$vote->userId,
			$vote->positions,
			$vote->createdAt,
			true,
		);
	}

	public function countsFor(string $objectId): array
	{
		$counts = [];
		foreach ($this->votes as $vote) {
			if ($vote->objectId !== $objectId) {
				continue;
			}

			foreach ($vote->positions as $position) {
				$counts[$position] = ($counts[$position] ?? 0) + 1;
			}
		}

		return $counts;
	}

	public function pendingCountsFor(string $objectId, DateTimeImmutable $peerRevisionAt): array
	{
		$revision = Time::toSecondPrecision($peerRevisionAt);
		$counts = [];
		foreach ($this->votes as $vote) {
			if ($vote->objectId !== $objectId) {
				continue;
			}

			if ($vote->queued && $vote->createdAt < $revision) {
				continue;
			}

			foreach ($vote->positions as $position) {
				$counts[$position] = ($counts[$position] ?? 0) + 1;
			}
		}

		return $counts;
	}
}
