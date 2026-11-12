<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;

/**
 * `GET /api/v1/timelines/public?limit=&before=`
 */
final class PublicTimelineHandler implements Handler
{
	public function __construct(
		private readonly TimelineService $timelines,
		private readonly ?MediaService $media = null,
	) {
	}

	public function handle(Request $request): Response
	{
		try {
			$posts = $this->timelines->public(
				$request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'posts' => $this->media === null
				? array_map(static fn (Post $post): array => $post->toArray(), $posts)
				: $this->media->presentPosts($posts),
		]);
	}
}
