<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PDOException;

use function array_slice;
use function array_values;
use function count;
use function implode;
use function json_encode;
use function max;
use function mb_strtolower;
use function min;
use function str_replace;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The SQL discovery index.
 *
 * Matching is a case-insensitive substring over content and the JSON alias
 * list. Ordering uses a small deterministic CASE rank, then recency and id.
 * The cursor conditions run on the same subquery, so an exact-match page
 * cannot be spliced with a later substring-only page.
 */
final class PdoSearchIndex implements SearchIndex
{
	private const COLUMNS = 'id, kind, source, author_id, content, hashtags, aliases, visibility, published_at, cached_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function upsert(SearchRecord $record): void
	{
		$params = [
			'id' => $record->id,
			'kind' => $record->kind->value,
			'source' => $record->source->value,
			'author_id' => $record->authorId,
			'content' => $record->content,
			'hashtags' => json_encode($record->hashtags, JSON_THROW_ON_ERROR),
			'aliases' => json_encode($record->aliases, JSON_THROW_ON_ERROR),
			'visibility' => $record->visibility->value,
			'published_at' => Time::format($record->publishedAt),
			'cached_at' => Time::format($record->cachedAt),
		];

		try {
			$changed = $this->db->execute(
				'UPDATE ' . SearchIndexSchema::TABLE
				. ' SET kind = :kind, source = :source, author_id = :author_id, content = :content,'
				. ' hashtags = :hashtags, aliases = :aliases, visibility = :visibility,'
				. ' published_at = :published_at, cached_at = :cached_at'
				. ' WHERE id = :id',
				$params,
			);

			if ($changed === 0 && $this->find($record->id) === null) {
				$this->db->execute(
					'INSERT INTO ' . SearchIndexSchema::TABLE
					. ' (' . self::COLUMNS . ') VALUES ('
					. ':id, :kind, :source, :author_id, :content, :hashtags, :aliases, :visibility, :published_at, :cached_at)',
					$params,
				);
			}
		} catch (PDOException $exception) {
			throw new DatabaseException('The search record could not be stored.', 0, $exception);
		}
	}

	public function find(string $id): ?SearchRecord
	{
		try {
			$row = $this->db->fetchOne(
				'SELECT ' . self::COLUMNS . ' FROM ' . SearchIndexSchema::TABLE . ' WHERE id = :id',
				['id' => $id],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The search record could not be read.', 0, $exception);
		}

		return $row === null ? null : SearchRecord::fromRow($row);
	}

	public function remove(string $id): void
	{
		try {
			$this->db->execute(
				'DELETE FROM ' . SearchIndexSchema::TABLE . ' WHERE id = :id',
				['id' => $id],
			);
		} catch (PDOException $exception) {
			throw new DatabaseException('The search record could not be removed.', 0, $exception);
		}
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

		$normalized = mb_strtolower($query);
		$escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized);
		$params = [
			'query_exact' => $normalized,
			'query_prefix' => $escaped . '%',
			'query_like' => '%' . $escaped . '%',
			'visibility' => Visibility::Public->value,
		];

		$kindPlaceholders = [];
		foreach (array_values($kinds) as $index => $kind) {
			$name = 'kind_' . $index;
			$kindPlaceholders[] = ':' . $name;
			$params[$name] = $kind->value;
		}

		$inner = 'SELECT ' . self::COLUMNS
			. ", CASE WHEN LOWER(content) = :query_exact THEN 3"
			. " WHEN LOWER(content) LIKE :query_prefix ESCAPE '!' THEN 2 ELSE 1 END AS sort_rank"
			. ' FROM ' . SearchIndexSchema::TABLE
			. ' WHERE visibility = :visibility'
			. ' AND kind IN (' . implode(', ', $kindPlaceholders) . ')'
			. " AND (LOWER(content) LIKE :query_like ESCAPE '!' OR LOWER(aliases) LIKE :query_like ESCAPE '!')";

		if ($excludeAuthorIds !== []) {
			$authorPlaceholders = [];
			foreach (array_values($excludeAuthorIds) as $index => $authorId) {
				$name = 'exclude_' . $index;
				$authorPlaceholders[] = ':' . $name;
				$params[$name] = $authorId;
			}
			$inner .= ' AND (author_id IS NULL OR author_id NOT IN (' . implode(', ', $authorPlaceholders) . '))';
		}

		$sql = 'SELECT * FROM (' . $inner . ') ranked';
		if ($cursor !== null) {
			$sql .= ' WHERE (ranked.sort_rank < :cursor_rank'
				. ' OR (ranked.sort_rank = :cursor_rank AND ranked.published_at < :cursor_published_at)'
				. ' OR (ranked.sort_rank = :cursor_rank AND ranked.published_at = :cursor_published_at'
				. ' AND ranked.id < :cursor_id))';
			$params['cursor_rank'] = $cursor->rank;
			$params['cursor_published_at'] = Time::format($cursor->publishedAt);
			$params['cursor_id'] = $cursor->id;
		}

		$sql .= ' ORDER BY ranked.sort_rank DESC, ranked.published_at DESC, ranked.id DESC'
			. ' LIMIT ' . ($limit + 1);

		try {
			$rows = $this->db->fetchAll($sql, $params);
		} catch (PDOException $exception) {
			throw new DatabaseException('The search index could not be queried.', 0, $exception);
		}

		$records = [];
		foreach ($rows as $row) {
			$records[] = SearchRecord::fromRow($row);
		}

		$hasMore = count($records) > $limit;
		$records = array_slice($records, 0, $limit);
		$next = $hasMore && $records !== []
			? SearchCursor::after($records[count($records) - 1], $query)
			: null;

		return new SearchPage($records, $next);
	}
}
