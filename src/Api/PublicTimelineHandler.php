<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;

/**
 * `GET /api/v1/timelines/public?limit=&before=&include_filtered=`
 *
 * Anonymous readers have no rules, so the page is unfiltered. A signed-in
 * reader gets the same visibility decision as before, followed by the
 * reader-private `public` context rules.
 */
final class PublicTimelineHandler implements Handler
{
	private readonly ?PostFilterPresenter $presenter;

	public function __construct(
		private readonly TimelineService $timelines,
		private readonly ?MediaService $media = null,
		?FilterService $filters = null,
	) {
		$this->presenter = $filters === null ? null : new PostFilterPresenter($filters, $media);
	}

	public function handle(Request $request): Response
	{
		$limit = $request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT);

		try {
			$posts = $this->timelines->public(
				min(TimelineService::MAX_LIMIT, $limit * 2),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		if ($this->presenter === null || $request->userId === null) {
			$presented = $this->media === null
				? array_map(static fn ($post): array => $post->toArray(), $posts)
				: $this->media->presentPosts($posts);

			return Response::json(['posts' => array_slice($presented, 0, $limit)]);
		}

		return Response::json([
			'posts' => array_slice(
				$this->presenter->present(
					$request->userId,
					FilterContext::Public,
					$posts,
					FilterRequestParser::includeFiltered($request),
				),
				0,
				$limit,
			),
		]);
	}
}
