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
 * `POST {mastodon.prefix}/notifications/clear`
 *
 * Marks every unread notification of the signed-in account read and returns
 * the Mastodon empty object. Repeating the request is a successful no-op.
 */
final class MastodonClearNotificationsHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respondEmptyObject(function () use ($request): void {
			$this->mastodon->clearNotifications((string) $request->userId);
		});
	}
}
