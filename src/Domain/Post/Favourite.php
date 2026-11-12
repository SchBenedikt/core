<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;

/**
 * One local account's favourite of a local post.
 *
 * A favourite is a private relationship: other accounts may see the public
 * count, but not who created it. The pair (user, post) is the identity, so a
 * retry cannot create a second row.
 */
final readonly class Favourite
{
	public const MAX_USER_LENGTH = 255;

	public const MAX_POST_LENGTH = 64;

	public function __construct(
		public string $userId,
		public string $postId,
		public DateTimeImmutable $createdAt,
	) {
		if ($userId === '' || mb_strlen($userId) > self::MAX_USER_LENGTH) {
			throw new InvalidArgumentException('A favourite needs a user id of at most 255 characters.');
		}

		if ($postId === '' || mb_strlen($postId) > self::MAX_POST_LENGTH) {
			throw new InvalidArgumentException('A favourite needs a post id of at most 64 characters.');
		}
	}
}
