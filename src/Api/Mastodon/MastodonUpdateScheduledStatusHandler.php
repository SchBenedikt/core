<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;

use function is_string;
use function trim;

/**
 * `PUT {mastodon.prefix}/scheduled_statuses/{id}`
 *
 * Mastodon only lets a scheduled status change its time through this route.
 */
final class MastodonUpdateScheduledStatusHandler extends MastodonScheduledHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(function () use ($request): array {
			$raw = $request->payload()['scheduled_at'] ?? null;
			if (!is_string($raw) || trim($raw) === '') {
				throw new MastodonRequestException(422, 'validation_failed', 'scheduled_at is required.');
			}

			try {
				$scheduledAt = Time::parseIso8601($raw);
			} catch (InvalidArgumentException) {
				throw new MastodonRequestException(
					422,
					'validation_failed',
					'scheduled_at must be an ISO 8601 timestamp.',
				);
			}

			return $this->presenter->present($this->scheduled->reschedule(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
				$scheduledAt,
			));
		});
	}
}
