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

use function array_is_list;
use function count;
use function in_array;
use function sort;

/**
 * One local account's choices in a remote poll.
 *
 * The row is the idempotency boundary for optimistic voting: a retry with the
 * same choices is a no-op, and the delivery state tells the client whether the
 * signed Notes were already queued.
 */
final readonly class RemotePollVote
{
	public const MAX_POSITIONS = RemotePoll::MAX_OPTIONS;

	/** @var list<int> */
	public array $positions;

	public DateTimeImmutable $createdAt;

	/**
	 * @param list<int> $positions
	 */
	public function __construct(
		public string $objectId,
		public string $userId,
		array $positions,
		DateTimeImmutable $createdAt,
		public bool $queued = false,
	) {
		if ($objectId === '' || $userId === '') {
			throw new InvalidArgumentException('A remote poll vote needs a poll and a voter.');
		}

		if (!array_is_list($positions) || $positions === []) {
			throw new InvalidArgumentException('A remote poll vote needs at least one option.');
		}

		if (count($positions) > self::MAX_POSITIONS) {
			throw new InvalidArgumentException('A remote poll vote selects too many options.');
		}

		$seen = [];
		foreach ($positions as $position) {
			if (!is_int($position) || $position < 0 || $position >= self::MAX_POSITIONS) {
				throw new InvalidArgumentException('A remote poll vote position is invalid.');
			}

			if (in_array($position, $seen, true)) {
				throw new InvalidArgumentException('A remote poll vote cannot select one option twice.');
			}

			$seen[] = $position;
		}

		sort($positions);
		$this->positions = $positions;
		$this->createdAt = Time::toSecondPrecision($createdAt);
	}

	/**
	 * @return array{object_id: string, user_id: string, positions: list<int>, created_at: string, queued: bool}
	 */
	public function toArray(): array
	{
		return [
			'object_id' => $this->objectId,
			'user_id' => $this->userId,
			'positions' => $this->positions,
			'created_at' => Time::format($this->createdAt),
			'queued' => $this->queued,
		];
	}
}
