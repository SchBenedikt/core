<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignalKind;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Recommendation\RecommendationConsentRequiredException;
use AlohaSocial\Core\Service\Recommendation\RecommendationDisabledException;
use AlohaSocial\Core\Service\Recommendation\RecommendationLimitException;
use AlohaSocial\Core\Service\Recommendation\RecommendationService;

use function is_string;

/**
 * `POST /api/v1/recommendations/signals`
 *
 * Only explicit interactions are accepted. There is no endpoint for inferred
 * dwell time or cross-site behavior.
 */
final class CreateRecommendationSignalHandler implements Handler
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
		$candidateId = $input['candidate_id'] ?? null;
		$rawKind = $input['kind'] ?? null;
		$fields = [];
		if (!is_string($candidateId) || $candidateId === '' || strlen($candidateId) > RecommendationCandidate::MAX_ID_LENGTH) {
			$fields['candidate_id'] = 'A valid candidate id is required.';
		}

		$kind = is_string($rawKind) ? RecommendationSignalKind::tryFrom($rawKind) : null;
		if ($kind === null) {
			$fields['kind'] = 'Use impression, click, like, reply, repost or bookmark.';
		}

		if ($fields !== []) {
			throw HttpException::validationFailed($fields);
		}

		try {
			$signal = $this->recommendations->recordSignal($userId, $kind, $candidateId);
		} catch (RecommendationDisabledException $exception) {
			throw new HttpException(503, 'recommendations_disabled', $exception->getMessage());
		} catch (RecommendationConsentRequiredException $exception) {
			throw new HttpException(403, 'recommendation_consent_required', $exception->getMessage());
		} catch (RecommendationLimitException $exception) {
			throw new HttpException(429, 'recommendation_signal_limit', $exception->getMessage(), ['Retry-After' => '86400']);
		}

		return Response::json(['signal' => $signal->toArray()], 201, ['Cache-Control' => 'private, no-store']);
	}
}
