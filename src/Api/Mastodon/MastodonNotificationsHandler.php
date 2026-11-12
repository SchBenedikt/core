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

/**
 * `GET {mastodon.prefix}/notifications`
 *
 * Both `types[]` and `exclude_types[]` are accepted. Unknown type names are
 * ignored, matching the forward-compatible behaviour clients expect when a
 * newer Mastodon type reaches an older server.
 */
final class MastodonNotificationsHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$limit = $this->mastodon->clamp($request->queryInt(
			'limit',
			MastodonService::DEFAULT_LIMIT,
			1,
			MastodonService::MAX_LIMIT,
		));
		$types = $this->listQuery($request, 'types');
		$excludeTypes = $this->listQuery($request, 'exclude_types');

		$notifications = $this->mastodon->notifications(
			(string) $request->userId,
			$limit,
			$request->queryParam('max_id'),
			$request->queryParam('since_id'),
			$request->queryParam('min_id'),
			$types,
			$excludeTypes,
		);

		$response = Response::json($notifications);
		if ($notifications === []) {
			return $response;
		}

		$extra = [];
		if ($types !== []) {
			$extra['types'] = $types;
		}
		if ($excludeTypes !== []) {
			$extra['exclude_types'] = $excludeTypes;
		}

		return $response->withHeader('Link', $this->nextLink(
			$request,
			(string) $notifications[array_key_last($notifications)]['id'],
			$limit,
			$extra,
		));
	}

}
