<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use InvalidArgumentException;

/**
 * Where a per-reader rule applies.
 *
 * The vocabulary is the Mastodon v2 one, because the compatibility API and the
 * neutral API must mean the same thing by `home` or `account`. A rule without
 * a context would silently apply everywhere, so every rule states at least one.
 */
enum FilterContext: string
{
	case Home = 'home';
	case Public = 'public';
	case Notifications = 'notifications';
	case Thread = 'thread';
	case Account = 'account';

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $context): string => $context->value, self::cases());
	}

	/**
	 * @throws InvalidArgumentException for a value outside the contract
	 */
	public static function fromString(string $value): self
	{
		$context = self::tryFrom(strtolower(trim($value)));
		if ($context === null) {
			throw new InvalidArgumentException('Unknown filter context: ' . $value);
		}

		return $context;
	}
}
