<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Scheduling\ScheduledPostConflictException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ScheduledPostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;

use function is_string;
use function trim;

/**
 * `PATCH /api/v1/scheduled-posts/{id}`
 *
 * Only the time can change; the content of a scheduled write is immutable, so
 * an edit cannot be confused with a publication that is already queued.
 */
final class UpdateScheduledPostHandler implements Handler
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

		$rawScheduledAt = $request->json()['scheduled_at'] ?? null;
		if (!is_string($rawScheduledAt) || trim($rawScheduledAt) === '') {
			throw HttpException::validationFailed(['scheduled_at' => 'scheduled_at is required.']);
		}

		try {
			$scheduledAt = Time::parseIso8601($rawScheduledAt);
		} catch (InvalidArgumentException) {
			throw HttpException::validationFailed(['scheduled_at' => 'scheduled_at must be an ISO 8601 timestamp.']);
		}

		try {
			$scheduled = $this->scheduled->reschedule(
				$userId,
				(string) $request->attribute('id', ''),
				$scheduledAt,
			);
		} catch (ScheduledPostNotFoundException) {
			throw HttpException::notFound('Scheduled post not found.');
		} catch (ScheduledPostConflictException) {
			throw new HttpException(409, 'conflict', 'The scheduled post can no longer be changed.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['scheduled_post' => $scheduled->toArray()]);
	}
}
