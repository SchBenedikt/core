<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FollowRequestNotFoundException;
use AlohaSocial\Core\Service\FollowRequestService;

/**
 * `POST /api/v1/follow-requests/{id}/reject`
 *
 * Rejection queues the signed Reject and removes the pending row. A second
 * call is a 404, so a retry cannot send a second response.
 */
final class RejectFollowRequestHandler implements Handler
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
			$this->requests->reject($userId, (string) $request->attribute('id', ''));
		} catch (FollowRequestNotFoundException) {
			throw new HttpException(404, 'follow_request_not_found', 'Follow request not found.');
		}

		return Response::json(['rejected' => true], 202);
	}
}
