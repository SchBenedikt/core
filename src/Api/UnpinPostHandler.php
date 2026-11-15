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
use AlohaSocial\Core\Service\PinService;

/**
 * `DELETE /api/v1/pins/{postId}`
 */
final class UnpinPostHandler implements Handler
{
	public function __construct(
		private readonly PinService $pins,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$this->pins->unpin($userId, (string) $request->attribute('postId', ''));

		return Response::empty(204);
	}
}
