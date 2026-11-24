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

/** `GET /api/v1/timelines/direct?limit=&before=&before_id=` */
final class DirectTimelineHandler implements Handler
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
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$limit = $request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT);
		$fetch = min(TimelineService::MAX_LIMIT, $limit * 2);
		try {
			$posts = $this->timelines->direct(
				$userId,
				$fetch,
				$request->queryParam('before'),
				$request->queryParam('before_id'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		$presented = $this->presenter === null
			? ($this->media === null
				? array_map(static fn ($post): array => $post->toArray(), $posts)
				: $this->media->presentPosts($posts))
			: $this->presenter->present(
				$userId,
				FilterContext::Thread,
				$posts,
				FilterRequestParser::includeFiltered($request),
			);

		$hasExtra = count($presented) > $limit;
		$visible = array_slice($presented, 0, $limit);
		$cursorPost = $hasExtra
			? $visible[array_key_last($visible)]
			: ($posts === [] ? null : $posts[array_key_last($posts)]->toArray());
		return Response::json([
			'posts' => $visible,
			'has_more' => $hasExtra || count($posts) >= $fetch,
			'next_before' => $cursorPost['published_at'] ?? null,
			'next_before_id' => $cursorPost['id'] ?? null,
		]);
	}
}
