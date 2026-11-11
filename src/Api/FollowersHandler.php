<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\InvalidCursorException;

/**
 * `GET /api/v1/followers?limit=&before=`
 *
 * Lists the followers of the signed-in account, newest first, plus the total
 * so a client can show "n followers" without paging through all of them.
 */
final class FollowersHandler implements Handler
{
	public function __construct(
		private readonly FollowerService $followers,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$followers = $this->followers->followers(
				$userId,
				$request->queryInt('limit', FollowerService::DEFAULT_LIMIT, 1, FollowerService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'followers' => array_map(static fn (Follower $follower): array => $follower->toArray(), $followers),
			'total' => $this->followers->count($userId),
		]);
	}
}
