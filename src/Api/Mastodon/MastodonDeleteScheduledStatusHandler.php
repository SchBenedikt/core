<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Domain\Scheduling\ScheduledPostConflictException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostNotFoundException;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `DELETE {mastodon.prefix}/scheduled_statuses/{id}`
 *
 * Cancels the schedule; the compatibility shape has no body for this call.
 */
final class MastodonDeleteScheduledStatusHandler extends MastodonScheduledHandler
{
	public function handle(Request $request): Response
	{
		try {
			$this->scheduled->cancel((string) $request->userId, (string) $request->attribute('id', ''));
		} catch (ScheduledPostNotFoundException) {
			throw HttpException::notFound('Scheduled status not found.');
		} catch (ScheduledPostConflictException) {
			throw new HttpException(409, 'conflict', 'The scheduled status can no longer be cancelled.');
		}

		return Response::empty();
	}
}
