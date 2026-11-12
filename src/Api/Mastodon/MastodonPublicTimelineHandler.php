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
 * `GET {mastodon.prefix}/timelines/public`
 */
final class MastodonPublicTimelineHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$limit = $this->mastodon->clamp($request->queryInt(
			'limit',
			MastodonService::DEFAULT_LIMIT,
			1,
			1000,
		));

		$statuses = $this->mastodon->publicTimeline(
			$limit,
			$request->queryParam('max_id'),
			$request->queryParam('since_id'),
			$request->queryParam('min_id'),
		);

		$response = Response::json($statuses);
		if ($statuses === []) {
			return $response;
		}

		return $response->withHeader('Link', $this->nextLink(
			$request,
			(string) $statuses[array_key_last($statuses)]['id'],
			$limit,
		));
	}

}
