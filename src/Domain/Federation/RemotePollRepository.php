<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Storage of remote Questions, keyed by the peer's object id.
 */
interface RemotePollRepository
{
	/**
	 * Stores the peer's current view, replacing an older version of the same
	 * question. A Create and a later Update therefore converge on one row.
	 */
	public function upsert(RemotePoll $poll): void;

	public function find(string $objectId): ?RemotePoll;

	/**
	 * Marks a poll closed, keeping the original options and counts.
	 *
	 * @return bool whether a row was changed
	 */
	public function markClosed(string $objectId, DateTimeImmutable $closedAt): bool;
}
