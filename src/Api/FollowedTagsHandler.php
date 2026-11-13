<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Timeline\FollowedHashtag;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\InvalidCursorException;
use AlohaSocial\Core\Service\InvalidHashtagException;

use function array_map;

/**
 * `GET /api/v1/followed_tags?limit=&before=&before_tag=`
 *
 * The caller's private followed tags, newest first. Both cursor parts are
 * exclusive: `before` is the last `created_at`, `before_tag` breaks a tie.
 */
final class FollowedTagsHandler implements Handler
{
	public function __construct(
		private readonly FollowedHashtagService $followedTags,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$tags = $this->followedTags->page(
				$userId,
				$request->queryInt(
					'limit',
					FollowedHashtagService::DEFAULT_LIMIT,
					1,
					FollowedHashtagService::MAX_LIMIT,
				),
				$request->queryParam('before'),
				$request->queryParam('before_tag'),
			);
		} catch (InvalidHashtagException $exception) {
			throw HttpException::badRequest('invalid_tag', $exception->getMessage());
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'tags' => array_map(
				static fn (FollowedHashtag $follow): array => $follow->toArray(),
				$tags,
			),
		]);
	}
}
