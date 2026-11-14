<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use DateTimeImmutable;

use function array_slice;
use function mb_stripos;
use function mb_strtolower;
use function strcmp;
use function trim;
use function usort;

/**
 * Trend counts in memory, mirroring the SQL adapter's replace-on-reindex
 * semantics.
 */
final class InMemoryTrendRepository implements TrendRepository
{
	/**
	 * @var array<string, SearchRecord>
	 */
	private array $entries = [];

	public function record(SearchRecord $record): void
	{
		if ($record->hashtags === []) {
			unset($this->entries[$record->id]);

			return;
		}

		$this->entries[$record->id] = $record;
	}

	public function remove(string $entryId): void
	{
		unset($this->entries[$entryId]);
	}

	public function top(
		DateTimeImmutable $since,
		int $limit,
		?string $query = null,
	): array {
		$limit = max(1, min(100, $limit));
		$query = mb_strtolower(trim($query ?? ''));

		$counts = [];
		foreach ($this->entries as $record) {
			if ($record->publishedAt < $since) {
				continue;
			}

			foreach ($record->hashtags as $hashtag) {
				if ($query !== '' && mb_stripos($hashtag, $query) === false) {
					continue;
				}

				$counts[$hashtag] = ($counts[$hashtag] ?? 0) + 1;
			}
		}

		$trends = [];
		foreach ($counts as $hashtag => $count) {
			$trends[] = new Trend($hashtag, $count);
		}

		usort($trends, static function (Trend $a, Trend $b): int {
			$byCount = $b->count <=> $a->count;

			return $byCount !== 0 ? $byCount : strcmp($a->hashtag, $b->hashtag);
		});

		return array_slice($trends, 0, $limit);
	}
}
