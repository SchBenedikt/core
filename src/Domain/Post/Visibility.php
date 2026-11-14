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
 *
 * Readability and discoverability are two different questions:
 * {@see self::isPubliclyVisible()} answers "may this be fetched by link?" and
 * {@see self::mayListPublicly()} answers "may this appear in a listing?".
 * Unlisted is the case where the two differ.
 */
enum Visibility: string
{
	case Public = 'public';
	case Unlisted = 'unlisted';
	case Followers = 'followers';
	case Direct = 'direct';

	/**
	 * Whether anybody with the URL may read the post, signed in or not.
	 *
	 * This is about dereferenceability, not discovery: an unlisted post is
	 * readable by link while deliberately staying out of every listing.
	 */
	public function isPubliclyVisible(): bool
	{
		return $this === self::Public || $this === self::Unlisted;
	}

	/**
	 * Whether the post may be enumerated: public timelines, hashtag and
	 * followed-hashtag timelines, search and the actor outbox.
	 *
	 * Unlisted is readable but not discoverable, followers-only and direct are
	 * neither, so only a public post is listed.
	 */
	public function mayListPublicly(): bool
	{
		return $this === self::Public;
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
