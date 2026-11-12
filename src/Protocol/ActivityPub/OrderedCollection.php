<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

/**
 * The paged ActivityStreams collection shape used by the actor surface.
 *
 * The root document advertises `first`; a page is an `OrderedCollectionPage`
 * with `partOf`, `next` and `prev`. Page numbers are 1-based and a page URL
 * carries its own limit so a peer can follow the advertised links instead of
 * guessing query parameters.
 *
 * This class only builds documents; it never reads a repository. The caller
 * decides which page of items belongs in the document.
 */
final class OrderedCollection
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	/**
	 * A bound on page numbers keeps offset arithmetic and query input finite.
	 * A collection larger than this is beyond the current storage contract.
	 */
	public const MAX_PAGE = 1_000_000;

	/**
	 * @return array<string, mixed>
	 */
	public static function root(string $id, int $totalItems, int $limit = self::DEFAULT_LIMIT): array
	{
		$totalItems = max(0, $totalItems);
		$limit = self::clampLimit($limit);

		return [
			'@context' => ActivityFactory::CONTEXT,
			'id' => $id,
			'type' => 'OrderedCollection',
			'totalItems' => $totalItems,
			'first' => self::pageUrl($id, 1, $limit),
		];
	}

	/**
	 * @param list<mixed> $items
	 *
	 * @return array<string, mixed>
	 */
	public static function page(
		string $id,
		int $page,
		int $limit,
		int $totalItems,
		array $items,
	): array {
		$page = self::clampPage($page);
		$limit = self::clampLimit($limit);
		$totalItems = max(0, $totalItems);

		$document = [
			'@context' => ActivityFactory::CONTEXT,
			'id' => self::pageUrl($id, $page, $limit),
			'type' => 'OrderedCollectionPage',
			'partOf' => $id,
			'totalItems' => $totalItems,
			'orderedItems' => array_values($items),
		];

		$next = self::nextPage($page, $limit, $totalItems);
		if ($next !== null) {
			$document['next'] = self::pageUrl($id, $next, $limit);
		}

		if ($page > 1) {
			$document['prev'] = self::pageUrl($id, $page - 1, $limit);
		}

		return $document;
	}

	public static function pageUrl(string $id, int $page, int $limit = self::DEFAULT_LIMIT): string
	{
		$page = self::clampPage($page);
		$limit = self::clampLimit($limit);

		$query = '?page=' . $page;
		if ($limit !== self::DEFAULT_LIMIT) {
			$query .= '&limit=' . $limit;
		}

		return $id . $query;
	}

	public static function offset(int $page, int $limit = self::DEFAULT_LIMIT): int
	{
		return (self::clampPage($page) - 1) * self::clampLimit($limit);
	}

	public static function clampLimit(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	public static function clampPage(int $page): int
	{
		return max(1, min(self::MAX_PAGE, $page));
	}

	private static function nextPage(int $page, int $limit, int $totalItems): ?int
	{
		$offset = self::offset($page, $limit);

		return $offset + $limit < $totalItems ? $page + 1 : null;
	}
}
