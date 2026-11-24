<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonService;

/**
 * `GET {mastodon.prefix}/timelines/home`
 */
final class MastodonHomeTimelineHandler extends MastodonHandler
{
	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		if (($request->queryParam('min_id') ?? '') !== '') {
			throw HttpException::badRequest('unsupported_cursor', 'min_id is not supported by the home timeline.');
		}

		$limit = $this->mastodon->clamp($request->queryInt(
			'limit',
			MastodonService::DEFAULT_LIMIT,
			1,
			1000,
		));
		$statuses = $this->mastodon->homeTimeline(
			$userId,
			$limit,
			$request->queryParam('max_id'),
			$request->queryParam('since_id'),
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
