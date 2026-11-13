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
 * `POST /api/v1/notifications/{id}/dismiss`
 *
 * Sets the caller's durable read marker. A foreign or missing id is a 404; a
 * repeat dismiss of the caller's own already-read row is a successful no-op.
 */
final class DismissNotificationHandler implements Handler
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
			$dismissed = $this->accounts->dismiss(
				$userId,
				(string) $request->attribute('id', ''),
			);
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		if (!$dismissed) {
			throw HttpException::notFound('Notification not found.');
		}

		return Response::json(['dismissed' => true]);
	}
}
