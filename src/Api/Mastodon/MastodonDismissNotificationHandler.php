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
 * `POST {mastodon.prefix}/notifications/{id}/dismiss`
 *
 * A successful dismiss returns Mastodon's empty object. The notification is
 * marked read rather than hard-deleted, which makes a repeated dismiss of the
 * caller's own id idempotent while foreign ids remain hidden as 404.
 */
final class MastodonDismissNotificationHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		return $this->respondEmptyObject(function () use ($request): void {
			$this->mastodon->dismissNotification(
				(string) $request->userId,
				(string) $request->attribute('id', ''),
			);
		});
	}
}
