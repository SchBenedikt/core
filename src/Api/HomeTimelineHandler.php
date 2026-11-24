<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterMatch;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Support\Time;

/**
 * `GET /api/v1/timelines/home?limit=&before=&before_id=&include_filtered=`
 *
 * Filters run after the timeline has applied visibility and block rules. A
 * hidden entry is removed from the page; a warned entry stays, carries its
 * `filtered` evidence and has its content redacted until the reader asks for
 * it with `include_filtered`. The handler over-fetches one extra batch so a
 * page filtered down does not come back half empty.
 */
final class HomeTimelineHandler implements Handler
{
	private const MAX_FILTER_SCAN = 200;

	public function __construct(
		private readonly TimelineService $timelines,
		private readonly ?MediaService $media = null,
		private readonly ?FilterService $filters = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$limit = $request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT);
		$fetch = min(TimelineService::MAX_LIMIT, $limit * 2);
		$before = $request->queryParam('before');
		$beforeId = $request->queryParam('before_id');
		$kept = [];
		$matches = [];
		$scanned = 0;
		$nextBefore = null;
		$nextBeforeId = null;
		$hasMore = false;

		try {
			// A hidden run must not make a populated feed look empty. Bound one
			// request's work; the returned cursor lets the client continue through
			// long runs of hidden entries without repeating already scanned rows.
			while ($scanned < self::MAX_FILTER_SCAN && count($kept) <= $limit) {
				$posts = $this->timelines->home(
					$userId,
					$fetch,
					$before,
					$beforeId,
					hideReplies: true,
				);
				if ($posts === []) {
					break;
				}

				$exhausted = count($posts) < $fetch;
				$continued = false;

				foreach ($posts as $entry) {
					++$scanned;
					$nextBefore = Time::format($entry->publishedAt);
					$nextBeforeId = $entry->id;
					$match = $this->filters?->matchEntry($userId, FilterContext::Home, $entry);
					if ($match?->action === FilterAction::Hide) {
						if ($scanned >= self::MAX_FILTER_SCAN) {
							$continued = true;
							break;
						}

						continue;
					}

					if ($match !== null) {
						$matches[$entry->id] = $match;
					}

					$kept[] = $entry;
					if (count($kept) > $limit) {
						$continued = true;
						break;
					}
					if ($scanned >= self::MAX_FILTER_SCAN) {
						$continued = true;
						break;
					}
				}

				if (count($kept) > $limit) {
					$hasMore = true;
					$next = $kept[$limit - 1];
					$nextBefore = Time::format($next->publishedAt);
					$nextBeforeId = $next->id;
					break;
				}

				if ($continued) {
					$hasMore = true;
					break;
				}

				if ($exhausted || $nextBefore === null || $nextBeforeId === null) {
					break;
				}
				$before = $nextBefore;
				$beforeId = $nextBeforeId;
			}
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		$includeFiltered = FilterRequestParser::includeFiltered($request);
		$presented = $this->media === null
			? array_map(static fn (TimelineEntry $entry): array => $entry->toArray(), $kept)
			: $this->media->presentTimelineEntries($kept);

		if ($matches !== [] && $this->filters !== null) {
			foreach ($presented as $index => $row) {
				$match = $matches[$row['id']] ?? null;
				if (!$match instanceof FilterMatch) {
					continue;
				}

				$presented[$index]['filtered'] = [$this->filters->filterBlock($match)];
				if (!$includeFiltered) {
					$presented[$index] = $this->filters->redact($presented[$index]);
				}
			}
		}

		return Response::json([
			'posts' => array_slice($presented, 0, $limit),
			'has_more' => $hasMore,
			'next_before' => $hasMore ? $nextBefore : null,
			'next_before_id' => $hasMore ? $nextBeforeId : null,
		]);
	}
}
