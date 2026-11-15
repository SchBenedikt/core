<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use DateTimeImmutable;

/**
 * A bounded, read-only source of public candidates.
 *
 * The source must not make a remote network call during a feed read. A PDO
 * adapter reads stored local posts and cached remote posts; a host may supply
 * another adapter over its own read model.
 */
interface RecommendationCandidateSource
{
	/**
	 * @return list<RecommendationCandidate>
	 */
	public function candidates(string $viewerId, int $limit, DateTimeImmutable $now): array;
}
