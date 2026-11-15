<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * The ranking contract version. Changing a score, filter or diversity rule is
 * a version change so a cached cursor cannot silently span two algorithms.
 */
final class RecommendationAlgorithm
{
	public const VERSION = 'for-you-v1';

	private function __construct()
	{
	}
}
