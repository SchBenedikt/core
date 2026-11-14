<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use function is_string;
use function strtolower;
use function trim;

/**
 * The ActivityStreams `eventStatus` values this core understands.
 *
 * An unknown status is deliberately not an error: peers invent values, and a
 * card that shows no status is better than refusing an otherwise valid Event.
 * A cancelled event is metadata, not a Delete.
 */
enum RemoteEventStatus: string
{
	case Tentative = 'tentative';

	case Confirmed = 'confirmed';

	case Cancelled = 'cancelled';

	public static function fromActivityValue(mixed $value): ?self
	{
		if (!is_string($value)) {
			return null;
		}

		$normalized = strtolower(trim($value));
		if ($normalized === '') {
			return null;
		}

		foreach (self::cases() as $case) {
			if ($case->value === $normalized) {
				return $case;
			}
		}

		return null;
	}
}
