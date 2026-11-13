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
 * One local account's private bookmark of a local post.
 *
 * The pair (user, post) is the identity, so a retry cannot create a second
 * row. A bookmark is not a public reaction: it has no count and no
 * notification, and only its owner can list it.
 */
final readonly class Bookmark
{
	public const MAX_USER_LENGTH = 255;

	public const MAX_POST_LENGTH = 64;

	public function __construct(
		public string $userId,
		public string $postId,
		public DateTimeImmutable $createdAt,
	) {
		if ($userId === '' || mb_strlen($userId) > self::MAX_USER_LENGTH) {
			throw new InvalidArgumentException('A bookmark needs a user id of at most 255 characters.');
		}

		if ($postId === '' || mb_strlen($postId) > self::MAX_POST_LENGTH) {
			throw new InvalidArgumentException('A bookmark needs a post id of at most 64 characters.');
		}
	}
}
