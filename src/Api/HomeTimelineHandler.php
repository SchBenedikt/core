<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;

/**
 * `GET /api/v1/timelines/home?limit=&before=`
 */
final class HomeTimelineHandler implements Handler
{
	public function __construct(
		private readonly TimelineService $timelines,
		private readonly ?MediaService $media = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$posts = $this->timelines->home(
				$userId,
				$request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'posts' => $this->media === null
				? array_map(static fn (TimelineEntry $entry): array => $entry->toArray(), $posts)
				: $this->media->presentTimelineEntries($posts),
		]);
	}
}
