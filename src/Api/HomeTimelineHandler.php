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

		try {
			$posts = $this->timelines->home(
				$userId,
				$fetch,
				$request->queryParam('before'),
				$request->queryParam('before_id'),
				hideReplies: true,
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		$includeFiltered = FilterRequestParser::includeFiltered($request);
		$kept = [];
		$matches = [];
		foreach ($posts as $entry) {
			$match = $this->filters?->matchEntry($userId, FilterContext::Home, $entry);
			if ($match?->action === FilterAction::Hide) {
				continue;
			}

			if ($match !== null) {
				$matches[$entry->id] = $match;
			}

			$kept[] = $entry;
		}

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

		return Response::json(['posts' => array_slice($presented, 0, $limit)]);
	}
}
