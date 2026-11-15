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
use AlohaSocial\Core\Service\Recommendation\RecommendationService;

/**
 * `GET /api/v1/recommendations/preferences`
 */
final class GetRecommendationPreferencesHandler implements Handler
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

		if (!$this->recommendations->enabled()) {
			throw new HttpException(503, 'recommendations_disabled', 'Recommendations are disabled on this instance.');
		}

		return Response::json(
			['preference' => $this->recommendations->preferences($userId)->toArray()],
			200,
			['Cache-Control' => 'private, no-store'],
		);
	}
}
