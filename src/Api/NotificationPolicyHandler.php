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
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `GET|PATCH /api/v1/notifications/policy`
 *
 * The policy is scoped to the signed-in account. A patch changes only the
 * categories that were sent; existing notifications keep their recorded status.
 */
final class NotificationPolicyHandler implements Handler
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

		if ($request->method === 'GET') {
			return Response::json(['policy' => $this->notifications->policyFor($userId)]);
		}

		try {
			$policy = $this->notifications->updatePolicy($userId, $request->json());
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		return Response::json(['policy' => $policy]);
	}
}
