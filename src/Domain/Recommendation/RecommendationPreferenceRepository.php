<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * Owner-scoped recommendation preferences.
 */
interface RecommendationPreferenceRepository
{
	public function preference(string $userId): ?RecommendationPreference;

	public function savePreference(RecommendationPreference $preference): void;

	public function deletePreference(string $userId): void;
}
