<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\DatabaseException;

/**
 * Account-move records in memory, for tests and the demo.
 *
 * It mirrors the SQL repository's primary-key and latest-outbound lookups so a
 * test can exercise resume and duplicate handling without a database.
 */
final class InMemoryAccountMoveRepository implements AccountMoveRepository
{
	/**
	 * @var array<string, AccountMove>
	 */
	private array $moves = [];

	public function insert(AccountMove $move): void
	{
		if (isset($this->moves[$move->eventId])) {
			throw new DatabaseException('The account move event id is already stored.');
		}

		$this->moves[$move->eventId] = $move;
	}

	public function find(string $eventId): ?AccountMove
	{
		return $this->moves[$eventId] ?? null;
	}

	public function findOutboundByOrigin(string $originActorId): ?AccountMove
	{
		$match = null;
		foreach ($this->moves as $move) {
			if ($move->direction !== AccountMoveDirection::Outbound
				|| $move->originActorId !== $originActorId
			) {
				continue;
			}

			if ($match === null || $move->createdAt > $match->createdAt) {
				$match = $move;
			}
		}

		return $match;
	}

	public function save(AccountMove $move): void
	{
		$this->moves[$move->eventId] = $move;
	}
}
