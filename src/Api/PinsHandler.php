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
 * `GET /api/v1/pins`
 */
final class PinsHandler implements Handler
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

		return Response::json(['pins' => $this->pins->forOwner($userId)], 200, ['Cache-Control' => 'private, no-store']);
	}
}
