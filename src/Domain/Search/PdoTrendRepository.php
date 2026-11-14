<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PDOException;

use function array_map;
use function max;
use function mb_strtolower;
use function min;
use function str_replace;
use function trim;

/**
 * SQL trend rows. The primary key makes recording idempotent; updating a
 * post deletes its old rows first so a removed hashtag stops contributing.
 */
final class PdoTrendRepository implements TrendRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function record(SearchRecord $record): void
	{
		try {
			$this->db->execute(
				'DELETE FROM ' . TrendSchema::TABLE . ' WHERE entry_id = :entry_id',
				['entry_id' => $record->id],
			);

			foreach ($record->hashtags as $hashtag) {
				$this->db->execute(
					'INSERT INTO ' . TrendSchema::TABLE . ' (entry_id, hashtag, published_at)'
					. ' VALUES (:entry_id, :hashtag, :published_at)',
					[
						'entry_id' => $record->id,
						'hashtag' => $hashtag,
						'published_at' => Time::format($record->publishedAt),
					],
				);
			}
		} catch (PDOException $exception) {
			throw new DatabaseException('The trend row could not be stored.', 0, $exception);
		}
	}

	public function remove(string $entryId): void
	{
		try {
			$this->db->execute(
				'DELETE FROM ' . TrendSchema::TABLE . ' WHERE entry_id = :entry_id',
				['entry_id' => $entryId],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The trend row could not be removed.', 0, $exception);
		}
	}

	public function top(
		DateTimeImmutable $since,
		int $limit,
		?string $query = null,
	): array {
		$limit = max(1, min(100, $limit));
		$params = [
			'since' => Time::format($since),
			'visibility' => 'public',
		];
		$sql = 'SELECT t.hashtag, COUNT(*) AS uses FROM ' . TrendSchema::TABLE . ' t'
			. ' INNER JOIN ' . SearchIndexSchema::TABLE . ' s ON s.id = t.entry_id'
			. ' WHERE t.published_at >= :since AND s.visibility = :visibility';

		$query = trim($query ?? '');
		if ($query !== '') {
			$escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query));
			$sql .= " AND LOWER(t.hashtag) LIKE :query ESCAPE '!'";
			$params['query'] = '%' . $escaped . '%';
		}

		$sql .= ' GROUP BY t.hashtag ORDER BY uses DESC, t.hashtag ASC LIMIT ' . $limit;

		try {
			$rows = $this->db->fetchAll($sql, $params);
		} catch (PDOException $exception) {
			throw new DatabaseException('The trends could not be queried.', 0, $exception);
		}

		return array_map(
			static fn (array $row): Trend => new Trend((string) $row['hashtag'], (int) $row['uses']),
			$rows,
		);
	}
}
