<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Domain\Post\Visibility;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function mb_stripos;
use function mb_strtolower;
use function strcmp;
use function trim;
use function usort;

/**
 * Search index in memory, for tests and the reference assembly.
 *
 * It mirrors the SQL adapter's ordering and cursor semantics. Matching uses
 * the same substring rule; the SQL adapter's case folding is its database's,
 * which is why the contract test only uses ASCII queries.
 */
final class InMemorySearchIndex implements SearchIndex
{
	/**
	 * @var array<string, SearchRecord>
	 */
	private array $records = [];

	public function upsert(SearchRecord $record): void
	{
		$this->records[$record->id] = $record;
	}

	public function find(string $id): ?SearchRecord
	{
		return $this->records[$id] ?? null;
	}

	public function remove(string $id): void
	{
		unset($this->records[$id]);
	}

	public function search(
		string $query,
		array $kinds,
		int $limit,
		?SearchCursor $cursor = null,
		array $excludeAuthorIds = [],
	): SearchPage {
		$query = trim($query);
		$limit = max(1, min(100, $limit));
		if ($query === '' || $kinds === []) {
			return new SearchPage([]);
		}

		$kindSet = [];
		foreach ($kinds as $kind) {
			$kindSet[$kind->value] = true;
		}

		$excluded = [];
		foreach ($excludeAuthorIds as $authorId) {
			$excluded[$authorId] = true;
		}

		$matches = array_values(array_filter(
			$this->records,
			static fn (SearchRecord $record): bool => $record->visibility === Visibility::Public
				&& isset($kindSet[$record->kind->value])
				&& ($record->authorId === null || !isset($excluded[$record->authorId]))
				&& $record->matches($query),
		));

		usort($matches, static function (SearchRecord $a, SearchRecord $b) use ($query): int {
			$byRank = $b->rank($query) <=> $a->rank($query);
			if ($byRank !== 0) {
				return $byRank;
			}

			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		if ($cursor !== null) {
			$matches = array_values(array_filter(
				$matches,
				static function (SearchRecord $record) use ($cursor, $query): bool {
					$rank = $record->rank($query);
					if ($rank !== $cursor->rank) {
						return $rank < $cursor->rank;
					}

					if ($record->publishedAt != $cursor->publishedAt) {
						return $record->publishedAt < $cursor->publishedAt;
					}

					return strcmp($record->id, $cursor->id) < 0;
				},
			));
		}

		$hasMore = count($matches) > $limit;
		$page = array_slice($matches, 0, $limit);
		$next = $hasMore && $page !== []
			? SearchCursor::after($page[count($page) - 1], $query)
			: null;

		return new SearchPage($page, $next);
	}
}
