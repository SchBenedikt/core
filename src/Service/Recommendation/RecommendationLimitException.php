<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Recommendation;

use RuntimeException;

final class RecommendationLimitException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The recommendation signal history has reached its limit; reset it before recording more.');
	}
}
