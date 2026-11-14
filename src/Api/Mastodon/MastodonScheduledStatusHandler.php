<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `GET {mastodon.prefix}/scheduled_statuses/{id}`
 */
final class MastodonScheduledStatusHandler extends MastodonScheduledHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(function () use ($request): array {
			$scheduled = $this->scheduled->findOwned(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
			);

			return $this->presenter->present($scheduled);
		});
	}
}
