<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * The per-reader hard filter applied before ranking and when serving a cached
 * snapshot. Implementations must fail closed.
 */
interface RecommendationEligibility
{
	/**
	 * Returns only candidates the reader may see right now.
	 *
	 * A batch keeps the block/mute and moderation lookups bounded to one feed
	 * read; calling this again for a cached snapshot re-applies current rules.
	 *
	 * @param list<RecommendationCandidate> $candidates
	 *
	 * @return list<RecommendationCandidate>
	 */
	public function filter(
		string $viewerId,
		array $candidates,
		RecommendationPreference $preference,
	): array;
}
