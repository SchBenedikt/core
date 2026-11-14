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

/**
 * `DELETE /api/v1/conversations/{id}/mute`
 *
 * An absent mute is a successful no-op; posts and replies were never touched.
 */
final class UnmuteConversationHandler implements Handler
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

		try {
			$removed = $this->notifications->unmuteConversation(
				$userId,
				(string) $request->attribute('id', ''),
			);
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		return Response::json(['unmuted' => $removed]);
	}
}
