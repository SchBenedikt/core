<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\ScheduledPostService;

/**
 * `GET {mastodon.prefix}/scheduled_statuses?limit=&max_id=`
 */
final class MastodonScheduledStatusesHandler extends MastodonScheduledHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(function () use ($request): array {
			$rows = $this->scheduled->forAuthor(
				(string) $request->userId,
				$request->queryInt('limit', ScheduledPostService::DEFAULT_LIMIT, 1, ScheduledPostService::MAX_LIMIT),
				$request->queryParam('max_id'),
			);

			return $this->presenter->presentMany($rows);
		});
	}
}
