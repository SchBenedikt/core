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
use AlohaSocial\Core\Service\Notification\NotificationRequestNotFoundException;
use AlohaSocial\Core\Service\NotificationService;

use function str_ends_with;

/**
 * `POST /notifications/requests/{id}/accept`
 * `POST /notifications/requests/{id}/dismiss`
 * `DELETE /notifications/requests/{id}`
 *
 * All three are scoped by the request id, which belongs to one recipient. A
 * foreign id answers 404 exactly like a missing one.
 */
final class NotificationRequestActionHandler implements Handler
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

		$id = (string) $request->attribute('id', '');
		if ($id === '') {
			throw HttpException::notFound('Notification request not found.');
		}

		try {
			if ($request->method === 'DELETE') {
				$this->notifications->revokeSender($userId, $id);

				return Response::json(['revoked' => true]);
			}

			if (str_ends_with($request->path, '/accept')) {
				return Response::json($this->notifications->acceptSender($userId, $id));
			}

			return Response::json($this->notifications->dismissSender($userId, $id));
		} catch (NotificationRequestNotFoundException) {
			throw HttpException::notFound('Notification request not found.');
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}
	}
}
