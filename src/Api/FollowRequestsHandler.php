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
use AlohaSocial\Core\Service\FollowRequestService;
use AlohaSocial\Core\Service\InvalidCursorException;

use function array_map;

/**
 * `GET /api/v1/follow-requests?limit=&before=`
 *
 * The signed-in account's pending requests. This is the owner-side inbox for
 * a locked profile; a public caller can never see it.
 */
final class FollowRequestsHandler implements Handler
{
	public function __construct(
		private readonly FollowRequestService $requests,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$pending = $this->requests->pending(
				$userId,
				$request->queryInt('limit', FollowRequestService::DEFAULT_LIMIT, 1, FollowRequestService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'requests' => array_map(static fn (Follower $follower): array => $follower->toArray(), $pending),
		]);
	}
}
