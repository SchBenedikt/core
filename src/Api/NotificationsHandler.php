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
use AlohaSocial\Core\Service\LocalAccountService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `GET /api/v1/notifications`
 *
 * One page of the caller's unread notifications, newest first, plus the
 * current unread count. `before` is a notification id owned by the caller, so
 * a page cursor cannot probe another account's events.
 */
final class NotificationsHandler implements Handler
{
	public function __construct(
		private readonly LocalAccountService $accounts,
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
			LocalAccountService::DEFAULT_NOTIFICATION_LIMIT,
			1,
			LocalAccountService::MAX_NOTIFICATION_LIMIT,
		);
		$before = $request->queryParam('before');

		try {
			$page = $this->accounts->notifications($userId, $limit, $before);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		return Response::json($page);
	}
}
