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

/**
 * `POST /api/v1/notifications/clear`
 *
 * Marks every unread notification of the caller read and reports how many
 * rows changed. Repeating the call is an idempotent zero-row success.
 */
final class ClearNotificationsHandler implements Handler
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

		try {
			$cleared = $this->accounts->clear($userId);
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		return Response::json(['cleared' => $cleared]);
	}
}
