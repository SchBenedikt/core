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
use AlohaSocial\Core\Service\FollowRequestNotFoundException;
use AlohaSocial\Core\Service\FollowRequestService;

/**
 * `POST /api/v1/follow-requests/{id}/accept`
 *
 * Only the owner of the pending request may accept it. The response is the
 * accepted request; the peer's signed Accept is queued separately.
 */
final class AcceptFollowRequestHandler implements Handler
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
			$follower = $this->requests->accept($userId, (string) $request->attribute('id', ''));
		} catch (FollowRequestNotFoundException) {
			throw new HttpException(404, 'follow_request_not_found', 'Follow request not found.');
		}

		return Response::json(['request' => $follower->toArray()]);
	}
}
