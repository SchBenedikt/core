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
use AlohaSocial\Core\Service\BoostService;

/**
 * `DELETE /api/v1/posts/{id}/boost`
 *
 * Removing an absent boost is a successful no-op, matching the idempotent
 * unfavourite semantics.
 */
final class UnboostPostHandler implements Handler
{
	public function __construct(
		private readonly BoostService $boosts,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$this->boosts->unboost($userId, (string) $request->attribute('id', ''));

		return Response::empty(204);
	}
}
