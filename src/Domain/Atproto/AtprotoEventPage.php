<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use InvalidArgumentException;

use function array_is_list;
use function mb_strlen;

/**
 * One pull from a source.
 *
 * `cursor` is opaque and supplied by the host adapter. `gapDetected` says the
 * adapter could not prove continuity (for example a relay reconnect); the
 * importer persists that as a reconciliation request instead of silently
 * claiming the stream is complete.
 */
final readonly class AtprotoEventPage
{
	/**
	 * @param list<AtprotoEvent> $events
	 */
	public function __construct(
		public array $events,
		public ?string $cursor,
		public bool $hasMore = false,
		public bool $gapDetected = false,
	) {
		if (!array_is_list($events)) {
			throw new InvalidArgumentException('An event page needs a list of events.');
		}

		foreach ($events as $event) {
			if (!$event instanceof AtprotoEvent) {
				throw new InvalidArgumentException('An event page contains a non-event value.');
			}
		}

		if ($cursor !== null && ($cursor === '' || mb_strlen($cursor) > AtprotoSyncCheckpoint::MAX_CURSOR_LENGTH)) {
			throw new InvalidArgumentException('An event page cursor is invalid.');
		}
	}
}
