<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use InvalidArgumentException;

/**
 * Who may see a post.
 *
 * The values are the ones the API and the client use; they are not a
 * federation concept by themselves, {@see \AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory}
 * maps them onto `to`/`cc`.
 */
enum Visibility: string
{
	case Public = 'public';
	case Unlisted = 'unlisted';
	case Followers = 'followers';
	case Direct = 'direct';

	/**
	 * Whether the post may appear in public timelines and be fetched by
	 * anybody, signed in or not.
	 */
	public function isPubliclyVisible(): bool
	{
		return $this === self::Public || $this === self::Unlisted;
	}

	/**
	 * @throws InvalidArgumentException for a value outside the contract
	 */
	public static function fromString(string $value): self
	{
		$normalized = strtolower(trim($value));
		$case = self::tryFrom($normalized);

		if ($case === null) {
			throw new InvalidArgumentException(
				'Unknown visibility "' . $value . '". Expected one of: '
				. implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())) . '.',
			);
		}

		return $case;
	}
}
