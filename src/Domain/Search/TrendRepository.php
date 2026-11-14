<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use DateTimeImmutable;

/**
 * Time-windowed hashtag counts derived from indexed public records.
 *
 * Recording is keyed by the search record id, so reindexing a post replaces
 * its previous tags instead of counting it twice. The read side never scans
 * posts for hashtags; it aggregates the indexed rows for the requested
 * window.
 */
interface TrendRepository
{
	public function record(SearchRecord $record): void;

	public function remove(string $entryId): void;

	/**
	 * @return list<Trend> highest count first, hashtag ascending on a tie
	 */
	public function top(
		DateTimeImmutable $since,
		int $limit,
		?string $query = null,
	): array;
}
