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
use AlohaSocial\Core\Service\ValidationException;

use function array_is_list;

/**
 * `PATCH /api/v1/recommendations/preferences`
 *
 * `behavioral_consent` is never implied: a reader has to send it explicitly,
 * and the default remains off.
 */
final class UpdateRecommendationPreferencesHandler implements Handler
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

		$input = $request->payload();
		if (array_is_list($input)) {
			throw HttpException::validationFailed(['body' => 'Expected a JSON object.']);
		}

		try {
			$preference = $this->recommendations->updatePreferences($userId, $input);
		} catch (RecommendationDisabledException $exception) {
			throw new HttpException(503, 'recommendations_disabled', $exception->getMessage());
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['preference' => $preference->toArray()], 200, ['Cache-Control' => 'private, no-store']);
	}
}
