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
 * `POST /api/v1/conversations/{id}/mute`
 *
 * The id may name any reply; the thread resolver stores the mute on the root.
 */
final class MuteConversationHandler implements Handler
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
			$mute = $this->notifications->muteConversation(
				$userId,
				(string) $request->attribute('id', ''),
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		return Response::json(['muted' => true, 'conversation' => $mute], 201);
	}
}
