<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * The `app.bsky.feed.like` and `app.bsky.feed.repost` records.
 *
 * Both carry only the subject strong reference and a creation time; the
 * account and the record key come from the write itself. They live in their
 * own collections, so the writer must be told which one a record belongs to.
 */
final class AtprotoInteractionRecord
{
	public const LIKE = 'app.bsky.feed.like';

	public const REPOST = 'app.bsky.feed.repost';

	/**
	 * @return array<string, mixed>
	 */
	public static function like(AtprotoStrongRef $subject, DateTimeImmutable $createdAt): array
	{
		return self::record(self::LIKE, $subject, $createdAt);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function repost(AtprotoStrongRef $subject, DateTimeImmutable $createdAt): array
	{
		return self::record(self::REPOST, $subject, $createdAt);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function record(
		string $collection,
		AtprotoStrongRef $subject,
		DateTimeImmutable $createdAt,
	): array {
		return [
			'$type' => $collection,
			'subject' => $subject->toArray(),
			'createdAt' => Time::format($createdAt),
		];
	}
}
