<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Recommendation;

use RuntimeException;

/**
 * A cursor is opaque and snapshot-bound. An expired snapshot is rejected
 * instead of starting a new ranking at the same offset, which would silently
 * replay a different feed.
 */
final class InvalidRecommendationCursorException extends RuntimeException
{
	public function __construct(public readonly string $cursor)
	{
		parent::__construct('The recommendation cursor is invalid or has expired.');
	}
}
