<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonService;

use function trim;

/**
 * `GET {mastodon.prefix}/notifications/unread_count`
 *
 * The count is capped like Mastodon's endpoint: 100 by default and at most
 * 1000. The same local type/account filters as the notification list apply.
 */
final class MastodonNotificationUnreadCountHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$limit = $request->queryInt(
			'limit',
			MastodonService::DEFAULT_NOTIFICATION_COUNT_LIMIT,
			1,
			MastodonService::MAX_NOTIFICATION_COUNT_LIMIT,
		);
		$types = $this->listQuery($request, 'types');
		$excludeTypes = $this->listQuery($request, 'exclude_types');
		$accountId = $request->queryParam('account_id');

		return $this->respond(fn (): array => [
			'count' => $this->mastodon->notificationUnreadCount(
				(string) $request->userId,
				$limit,
				$types,
				$excludeTypes,
				$accountId === null ? null : trim($accountId),
			),
		]);
	}
}
