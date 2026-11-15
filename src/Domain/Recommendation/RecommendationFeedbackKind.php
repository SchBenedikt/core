<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

/**
 * Explicit feedback. `hide` removes a candidate from future snapshots for the
 * owner; `more` and `less` adjust its score without affecting other readers.
 */
enum RecommendationFeedbackKind: string
{
	case More = 'more';

	case Less = 'less';

	case Hide = 'hide';
}
