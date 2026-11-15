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
use AlohaSocial\Core\Service\Recommendation\RecommendationDisabledException;
use AlohaSocial\Core\Service\Recommendation\RecommendationService;

/**
 * `DELETE /api/v1/recommendations/data`
 *
 * Removes the reader's preferences, feedback and signals. No other reader's
 * rows can be addressed.
 */
final class DeleteRecommendationDataHandler implements Handler
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
			$this->recommendations->reset($userId);
		} catch (RecommendationDisabledException $exception) {
			throw new HttpException(503, 'recommendations_disabled', $exception->getMessage());
		}

		return Response::empty();
	}
}
