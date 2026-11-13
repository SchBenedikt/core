<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function preg_match;

/**
 * One hashtag followed by one local account.
 *
 * The tag is already normalized by the caller: lower-case, no leading `#`,
 * and only letters, digits and underscores. Follows are private to the
 * account and idempotent by `(account, tag)`.
 */
final readonly class FollowedHashtag
{
	public const MAX_ACCOUNT_LENGTH = 255;

	public const MAX_TAG_LENGTH = 100;

	public function __construct(
		public string $accountId,
		public string $tag,
		public DateTimeImmutable $createdAt,
	) {
		if ($accountId === '' || mb_strlen($accountId) > self::MAX_ACCOUNT_LENGTH) {
			throw new InvalidArgumentException('A followed hashtag needs an account id of at most 255 characters.');
		}

		if (
			$tag === ''
			|| mb_strlen($tag) > self::MAX_TAG_LENGTH
			|| preg_match('/^[\p{L}\p{N}_]+$/u', $tag) !== 1
		) {
			throw new InvalidArgumentException('A followed hashtag needs a normalized tag of at most 100 characters.');
		}
	}

	/**
	 * @return array{tag: string, created_at: string}
	 */
	public function toArray(): array
	{
		return [
			'tag' => $this->tag,
			'created_at' => Time::format($this->createdAt),
		];
	}
}
