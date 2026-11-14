<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Search\SearchService;

/**
 * `GET /api/v1/trends?limit=&window=`
 *
 * Hashtag counts come from the indexed public trend rows. `window` is in
 * seconds and bounded, so a caller cannot turn one request into an unbounded
 * aggregation.
 */
final class TrendsHandler implements Handler
{
	public function __construct(
		private readonly SearchService $search,
	) {
	}

	public function handle(Request $request): Response
	{
		return Response::json($this->search->trends(
			$request->queryInt('limit', SearchService::DEFAULT_LIMIT, 1, SearchService::MAX_LIMIT),
			$request->queryInt(
				'window',
				SearchService::DEFAULT_TREND_WINDOW_SECONDS,
				60,
				SearchService::MAX_TREND_WINDOW_SECONDS,
			),
		));
	}
}
