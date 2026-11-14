<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use InvalidArgumentException;

/**
 * What happens when a rule matches.
 *
 * A `warn` match keeps the object and hands the reader an explicit marker plus
 * redacted content; `hide` removes it from the page. Hiding wins when both
 * match, so a rule that suppresses a topic cannot be weakened by a broader warn
 * rule on the same text.
 */
enum FilterAction: string
{
	case Hide = 'hide';
	case Warn = 'warn';

	/**
	 * @throws InvalidArgumentException for a value outside the contract
	 */
	public static function fromString(string $value): self
	{
		$action = self::tryFrom(strtolower(trim($value)));
		if ($action === null) {
			throw new InvalidArgumentException('Unknown filter action: ' . $value);
		}

		return $action;
	}
}
