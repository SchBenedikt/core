<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * The three owner-scoped stores behind one adapter. They stay separate ports
 * because a host may persist them differently; the combined interface is what
 * the default assembly needs.
 */
interface RecommendationRepository extends RecommendationPreferenceRepository, RecommendationFeedbackRepository, RecommendationSignalRepository
{
	public function deleteForUser(string $userId): void;
}
