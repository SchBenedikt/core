<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/** Builder and stable record key for app.bsky.graph.follow records. */
final class AtprotoFollowRecord
{
	public const COLLECTION = 'app.bsky.graph.follow';

	/** @return array<string, string> */
	public static function create(string $subjectDid, DateTimeImmutable $createdAt): array
	{
		if (!AtprotoIdentifiers::isDid($subjectDid)) {
			throw new InvalidArgumentException('An AT Protocol follow needs a valid subject DID.');
		}

		return [
			'$type' => self::COLLECTION,
			'subject' => $subjectDid,
			'createdAt' => Time::format($createdAt),
		];
	}

	public static function rkey(
		DateTimeImmutable $createdAt,
		string $localUserId,
		string $accountDid,
		string $subjectDid,
	): string {
		if ($localUserId === '' || !AtprotoIdentifiers::isDid($accountDid) || !AtprotoIdentifiers::isDid($subjectDid)) {
			throw new InvalidArgumentException('An AT Protocol follow key needs valid account and subject identifiers.');
		}

		// Use a fixed timestamp so concurrent workers derive the same key.
		return Tid::forRecord(new DateTimeImmutable('@0'), $localUserId . '|' . $accountDid . '|' . $subjectDid);
	}
}
