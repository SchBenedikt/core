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

use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function trim;

/**
 * `POST {mastodon.prefix}/accounts/{id}/mute`
 *
 * `notifications` defaults to false and only controls notification filtering,
 * not the home-timeline mute. `duration` is in seconds; zero or an absent
 * value means indefinite, matching Mastodon's mute dialog.
 */
final class MastodonMuteAccountHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$body = $request->payload();
		$notifications = $body['notifications'] ?? false;
		if (!is_bool($notifications)
			&& !in_array($notifications, [0, 1, '0', '1', 'true', 'false', 'on', 'off'], true)
		) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'notifications must be a boolean.',
			);
		}

		$duration = $this->duration($body['duration'] ?? null);

		return $this->respond(fn (): array => $this->mastodon->muteAccount(
			(string) $request->userId,
			(string) $request->attribute('id', ''),
			in_array($notifications, [true, 1, '1', 'true', 'on'], true),
			$duration,
		));
	}

	private function duration(mixed $value): ?int
	{
		if ($value === null || $value === '' || $value === 0 || $value === '0') {
			return null;
		}

		if (is_int($value)) {
			$seconds = $value;
		} elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
			$seconds = (int) trim($value);
		} else {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'duration must be a positive integer number of seconds.',
			);
		}

		if ($seconds < 1) {
			throw new MastodonRequestException(
				422,
				'validation_failed',
				'duration must be a positive integer number of seconds.',
			);
		}

		return $seconds;
	}
}
