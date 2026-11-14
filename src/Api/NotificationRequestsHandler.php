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
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\Notification\NotificationPolicyService;
use AlohaSocial\Core\Service\NotificationService;

use function count;

/**
 * `GET /api/v1/notifications/requests`
 *
 * Held senders, one row per actor, never the held events themselves.
 */
final class NotificationRequestsHandler implements Handler
{
	public function __construct(
		private readonly NotificationService $notifications,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$limit = $request->queryInt(
			'limit',
			NotificationPolicyService::DEFAULT_REQUEST_LIMIT,
			1,
			NotificationPolicyService::MAX_REQUEST_LIMIT,
		);

		try {
			$requests = $this->notifications->requests($userId, $limit);
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		return Response::json(['requests' => $requests, 'count' => count($requests)]);
	}
}
