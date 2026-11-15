<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Recommendation\InvalidRecommendationCursorException;
use AlohaSocial\Core\Service\Recommendation\RecommendationDisabledException;
use AlohaSocial\Core\Service\Recommendation\RecommendationService;

/**
 * `GET /api/v1/recommendations?limit=&cursor=`
 */
final class GetRecommendationsHandler implements Handler
{
	public function __construct(
		private readonly RecommendationService $recommendations,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$page = $this->recommendations->feed(
				$userId,
				$request->queryInt('limit', RecommendationService::DEFAULT_LIMIT, 1, RecommendationService::MAX_LIMIT),
				$request->queryParam('cursor'),
			);
		} catch (RecommendationDisabledException $exception) {
			throw new HttpException(503, 'recommendations_disabled', $exception->getMessage());
		} catch (InvalidRecommendationCursorException) {
			throw HttpException::badRequest('invalid_cursor', 'The recommendation cursor is invalid or has expired.');
		}

		return Response::json($page->toArray(), 200, ['Cache-Control' => 'private, no-store']);
	}
}
