<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;

/**
 * Remembers which activities have already been accepted.
 *
 * A valid signature is not enough: a peer (or anybody who captured the
 * request) can replay it unchanged. The activity id is claimed before it is
 * handled and released again if handling fails, so a failed delivery can be
 * retried but a successful one cannot be applied twice.
 */
interface ActivityLog
{
	/**
	 * @return bool true when the id was not seen before and is now claimed
	 */
	public function claim(string $activityId, DateTimeImmutable $now): bool;

	/**
	 * Gives a claim back, for a request that could not be handled.
	 */
	public function release(string $activityId): void;
}
