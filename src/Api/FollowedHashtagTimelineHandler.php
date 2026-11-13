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
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;

use function array_map;

/**
 * `GET /api/v1/timelines/followed_tags?limit=&before=`
 *
 * Posts carrying any tag the caller follows. The same limit, exclusive
 * timestamp cursor and public/unlisted visibility rules as the public tag
 * timeline apply; the followed tag list is repeated so a client can label the
 * screen without a second request.
 */
final class FollowedHashtagTimelineHandler implements Handler
{
	public function __construct(
		private readonly FollowedHashtagService $followedTags,
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

		$tags = $this->followedTags->tags($userId);

		try {
			$posts = $this->timelines->followedTags(
				$tags,
				$request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'tags' => $tags,
			'posts' => $this->media === null
				? array_map(static fn (Post $post): array => $post->toArray(), $posts)
				: $this->media->presentPosts($posts),
		]);
	}
}
