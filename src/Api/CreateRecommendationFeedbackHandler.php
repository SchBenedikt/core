<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedbackKind;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Recommendation\RecommendationDisabledException;
use AlohaSocial\Core\Service\Recommendation\RecommendationService;

use function is_string;

/**
 * `POST /api/v1/recommendations/feedback`
 */
final class CreateRecommendationFeedbackHandler implements Handler
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
		$action = $input['action'] ?? null;
		$fields = [];
		if (!is_string($candidateId) || $candidateId === '' || strlen($candidateId) > RecommendationCandidate::MAX_ID_LENGTH) {
			$fields['candidate_id'] = 'A valid candidate id is required.';
		}

		$kind = is_string($action) ? RecommendationFeedbackKind::tryFrom($action) : null;
		if ($kind === null) {
			$fields['action'] = 'Use more, less or hide.';
		}

		if ($fields !== []) {
			throw HttpException::validationFailed($fields);
		}

		try {
			$feedback = $this->recommendations->recordFeedback($userId, $candidateId, $kind);
		} catch (RecommendationDisabledException $exception) {
			throw new HttpException(503, 'recommendations_disabled', $exception->getMessage());
		}

		return Response::json(['feedback' => $feedback->toArray()], 200, ['Cache-Control' => 'private, no-store']);
	}
}
