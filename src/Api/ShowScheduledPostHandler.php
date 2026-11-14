<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Scheduling\ScheduledPostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ScheduledPostService;

/**
 * `GET /api/v1/scheduled-posts/{id}`
 */
final class ShowScheduledPostHandler implements Handler
{
	public function __construct(
		private readonly ScheduledPostService $scheduled,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$scheduled = $this->scheduled->findOwned($userId, (string) $request->attribute('id', ''));
		} catch (ScheduledPostNotFoundException) {
			throw HttpException::notFound('Scheduled post not found.');
		}

		return Response::json(['scheduled_post' => $scheduled->toArray()]);
	}
}
