<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

/**
 * A bounded page plus the cursor for the next page, if one exists.
 *
 * The cursor is created only when the repository saw one more row than the
 * page limit, so an exhausted result set never advertises an empty page.
 */
final readonly class SearchPage
{
	/**
	 * @param list<SearchRecord> $records
	 */
	public function __construct(
		public array $records,
		public ?SearchCursor $nextCursor = null,
	) {
	}
}
