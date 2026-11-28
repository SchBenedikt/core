<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

use function array_values;
use function in_array;

/**
 * Per-user poll votes in memory, for tests, the demo and tools.
 *
 * The optional counter is the in-memory equivalent of the SQL counter update:
 * it keeps the post's stored poll consistent with the vote rows. A vote is
 * stored only after the counter accepts it, so a failed update cannot leave a
 * half-written vote behind.
 */
final class InMemoryPollVoteRepository implements PollVoteRepository
{
	/**
	 * @var array<string, PollVote>
	 */
	private array $votes = [];

	public function __construct(
		private readonly ?PollVoteCounter $counter = null,
	) {
	}

	public function find(string $postId, string $userId): ?PollVote
	{
		return $this->votes[$this->key($postId, $userId)] ?? null;
	}

	public function findForPosts(string $userId, array $postIds): array
	{
		$votes = [];
		foreach (array_values($postIds) as $postId) {
			$vote = $this->find($postId, $userId);
			if ($vote !== null) {
				$votes[$postId] = $vote;
			}
		}

		return $votes;
	}

	public function insert(PollVote $vote): void
	{
		$key = $this->key($vote->postId, $vote->userId);
		if (isset($this->votes[$key])) {
			throw new DatabaseException('The user has already voted in this poll.');
		}

		$this->counter?->incrementPollVote($vote);
		$this->votes[$key] = $vote;
	}

	public function addPosition(string $postId, string $userId, int $position, DateTimeImmutable $votedAt): PollVote
	{
		$key = $this->key($postId, $userId);
		$existing = $this->votes[$key] ?? null;
		if ($existing !== null && in_array($position, $existing->positions, true)) {
			return $existing;
		}

		$positions = $existing === null ? [$position] : [...$existing->positions, $position];
		$vote = new PollVote($postId, $userId, $positions, $existing?->createdAt ?? $votedAt);

		if ($existing === null) {
			$this->insert($vote);

			return $vote;
		}

		// The counter runs before the ballot changes, so a failed update
		// cannot leave appended positions behind without matching counts.
		$this->counter?->addPollVotePosition($postId, $position);
		$this->votes[$key] = $vote;

		return $vote;
	}

	private function key(string $postId, string $userId): string
	{
		return $postId . "\0" . $userId;
	}
}
