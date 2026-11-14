<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * One page of an AppView timeline.
 *
 * `cursor` is opaque: pass it back unchanged to page forward. `null` means the
 * AppView has no further page.
 */
final readonly class AtprotoTimelinePage
{
	/**
	 * @param list<AtprotoTimelineItem> $items
	 */
	public function __construct(
		public array $items,
		public ?string $cursor,
	) {
	}
}
