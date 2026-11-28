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
	 * @param string|null $digest fingerprint of the accepted payload, kept so
	 *                            an identical retry can be told apart from a
	 *                            conflicting reuse of the same activity id
	 *
	 * @return bool true when the id was not seen before and is now claimed
	 */
	public function claim(string $activityId, DateTimeImmutable $now, ?string $digest = null): bool;

	/**
	 * The payload digest stored with a claim, or null when the id was never
	 * claimed or the claim predates digest recording.
	 */
	public function digest(string $activityId): ?string;

	/**
	 * Gives a claim back, for a request that could not be handled.
	 */
	public function release(string $activityId): void;
}
