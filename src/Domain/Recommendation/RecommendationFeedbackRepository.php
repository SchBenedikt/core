<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * Owner-scoped explicit feedback.
 */
interface RecommendationFeedbackRepository
{
	public function feedback(string $userId, string $candidateId): ?RecommendationFeedback;

	/**
	 * @return list<RecommendationFeedback>
	 */
	public function feedbackFor(string $userId): array;

	public function saveFeedback(RecommendationFeedback $feedback): void;

	public function deleteFeedback(string $userId, string $candidateId): bool;

	public function deleteFeedbackFor(string $userId): void;
}
