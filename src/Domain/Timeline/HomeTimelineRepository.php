<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use DateTimeImmutable;

/**
 * The read side of the home timeline.
 *
 * It combines the user's own local posts with remote posts and boosts from
 * actors in the outgoing follow graph, excluding actors the user blocks.
 * Keeping this in one repository means
 * the API never has to merge two ordered pages after the fact, which would
 * make the cursor incoherent.
 */
interface HomeTimelineRepository
{
	/**
	 * @return list<TimelineEntry>
	 */
	public function forUser(
		string $localUserId,
		int $limit,
		?DateTimeImmutable $before = null,
	): array;
}
