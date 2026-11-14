<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

/**
 * Read/write search index for public local and cached remote objects.
 *
 * The index is deliberately a separate port from the post and actor stores:
 * a host may run without a search index, and a host may use an external
 * search engine behind this contract. Indexing is idempotent by record id so
 * a retried write or a repeated WebFinger lookup cannot duplicate a result.
 *
 * The repository must filter by visibility again when reading, even though
 * {@see SearchRecord} refuses non-public rows. Defence in depth keeps an old
 * row written by an earlier version out of discovery.
 */
interface SearchIndex
{
	/**
	 * Creates or replaces one record. Replacing removes its old aliases and
	 * hashtags before the new values are stored.
	 */
	public function upsert(SearchRecord $record): void;

	public function find(string $id): ?SearchRecord;

	public function remove(string $id): void;

	/**
	 * @param list<SearchKind> $kinds
	 * @param list<string>     $excludeAuthorIds
	 *
	 * @return SearchPage ordered by rank, then newest first, then id
	 */
	public function search(
		string $query,
		array $kinds,
		int $limit,
		?SearchCursor $cursor = null,
		array $excludeAuthorIds = [],
	): SearchPage;
}
