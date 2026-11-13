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

use function array_is_list;
use function count;
use function in_array;
use function sort;

/**
 * One local account's choices in a poll.
 *
 * A vote stores option positions, not titles, so renaming a poll option (a
 * host concern) cannot change an existing vote. The positions are sorted for
 * stable storage and response ordering; a repeated position is rejected.
 */
final readonly class PollVote
{
	/** @var list<int> */
	public array $positions;

	public DateTimeImmutable $createdAt;

	/**
	 * @param list<int> $positions
	 */
	public function __construct(
		public string $postId,
		public string $userId,
		array $positions,
		DateTimeImmutable $createdAt,
	) {
		if (!array_is_list($positions) || $positions === []) {
			throw new InvalidArgumentException('A poll vote needs at least one option position.');
		}

		if (count($positions) > Poll::MAX_OPTIONS) {
			throw new InvalidArgumentException('A poll vote cannot select more options than a poll has.');
		}

		$seen = [];
		foreach ($positions as $position) {
			if (!is_int($position) || $position < 0 || $position >= Poll::MAX_OPTIONS) {
				throw new InvalidArgumentException('A poll vote position is invalid.');
			}

			if (in_array($position, $seen, true)) {
				throw new InvalidArgumentException('A poll vote cannot select one option twice.');
			}

			$seen[] = $position;
		}

		sort($positions);
		$this->positions = $positions;
		$this->createdAt = Time::toSecondPrecision($createdAt);
	}
}
