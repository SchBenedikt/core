<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;

use function array_map;

/**
 * `GET /api/v1/timelines/followed_tags?limit=&before=&before_id=&include_filtered=`
 *
 * Posts carrying any tag the caller follows. The same limit, exclusive
 * timestamp cursor and public/unlisted visibility rules as the public tag
 * timeline apply, and the caller's `public` filter rules run before
 * projection.
 */
final class FollowedHashtagTimelineHandler implements Handler
{
	private readonly ?PostFilterPresenter $presenter;

	public function __construct(
		private readonly FollowedHashtagService $followedTags,
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

		$tags = $this->followedTags->tags($userId);
		$limit = $request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT);

		try {
			$posts = $this->timelines->followedTags(
				$tags,
				min(TimelineService::MAX_LIMIT, $limit * 2),
				$request->queryParam('before'),
				$request->queryParam('before_id'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		$presented = $this->presenter === null
			? ($this->media === null
				? array_map(static fn (Post $post): array => $post->toArray(), $posts)
				: $this->media->presentPosts($posts))
			: $this->presenter->present(
				$userId,
				FilterContext::Public,
				$posts,
				FilterRequestParser::includeFiltered($request),
			);

		return Response::json([
			'tags' => $tags,
			'posts' => array_slice($presented, 0, $limit),
		]);
	}
}
