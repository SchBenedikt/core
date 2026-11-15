<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;

/**
 * Deterministic ranking seam. It receives only already-filtered candidates,
 * so a ranker cannot re-introduce a blocked or private item.
 */
interface RecommendationRanker
{
	/**
	 * @param list<RecommendationCandidate> $candidates
	 * @param list<RecommendationSignal>    $signals
	 * @param array<string, RecommendationFeedback> $feedback keyed by candidate id
	 *
	 * @return list<RecommendationItem>
	 */
	public function rank(
		string $viewerId,
		array $candidates,
		RecommendationPreference $preference,
		array $signals,
		array $feedback,
		DateTimeImmutable $now,
	): array;
}
