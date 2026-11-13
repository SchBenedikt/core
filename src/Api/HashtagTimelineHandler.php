<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\InvalidHashtagException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\TimelineService;

/**
 * `GET /api/v1/timelines/tag/{tag}?limit=&before=`
 *
 * A public tag timeline. Followers-only and direct posts are never included,
 * so the route needs no viewer and cannot leak a restricted post through a
 * guessed tag.
 */
final class HashtagTimelineHandler implements Handler
{
	public function __construct(
		private readonly TimelineService $timelines,
		private readonly ?MediaService $media = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$rawTag = (string) $request->attribute('tag', '');

		try {
			$posts = $this->timelines->tag(
				$rawTag,
				$request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidHashtagException $exception) {
			throw HttpException::badRequest('invalid_tag', $exception->getMessage());
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'tag' => Hashtags::normalize($rawTag) ?? '',
			'posts' => $this->media === null
				? array_map(static fn (Post $post): array => $post->toArray(), $posts)
				: $this->media->presentPosts($posts),
		]);
	}
}
