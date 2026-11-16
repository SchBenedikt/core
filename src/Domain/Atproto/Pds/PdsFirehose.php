<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use InvalidArgumentException;

/**
 * Read side of the durable firehose.
 *
 * The cursor is the last sequence a subscriber has seen; a replay returns
 * events strictly after it. The transport (WebSocket) is a host/process
 * concern and lives outside the domain.
 */
final readonly class PdsFirehose
{
	public function __construct(private PdsRepository $repository)
	{
	}

	public function latestSeq(): int
	{
		return $this->repository->latestSeq();
	}

	/**
	 * @return list<PdsEvent>
	 */
	public function after(int $cursor, int $limit = 100): array
	{
		if ($cursor < 0) {
			throw new InvalidArgumentException('A firehose cursor cannot be negative.');
		}

		return $this->repository->eventsAfter($cursor, max(1, min(500, $limit)));
	}

	/**
	 * A subscriber without a cursor starts at the current head.
	 */
	public function startingCursor(?string $cursor): int
	{
		if ($cursor === null || $cursor === '') {
			return $this->latestSeq();
		}

		if (preg_match('/^\d+$/', $cursor) !== 1) {
			throw new InvalidArgumentException('The firehose cursor must be a non-negative integer.');
		}

		return (int) $cursor;
	}
}
