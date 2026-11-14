<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Scheduling\ScheduledPost;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ScheduledPostService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `GET /api/v1/scheduled-posts?limit=&before=`
 */
final class ScheduledPostsHandler implements Handler
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
			$page = $this->scheduled->forAuthor(
				$userId,
				$request->queryInt('limit', ScheduledPostService::DEFAULT_LIMIT, 1, ScheduledPostService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json([
			'scheduled_posts' => array_map(
				static fn (ScheduledPost $scheduled): array => $scheduled->toArray(),
				$page,
			),
		]);
	}
}
