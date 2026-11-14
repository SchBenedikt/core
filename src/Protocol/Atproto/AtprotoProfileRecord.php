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
 * The `app.bsky.actor.profile` record.
 *
 * The record key is always `self`: a repository holds at most one profile per
 * account, and writing it again replaces the current one.
 */
final class AtprotoProfileRecord
{
	public const COLLECTION = 'app.bsky.actor.profile';

	public const RKEY = 'self';

	/**
	 * @return array<string, mixed>
	 */
	public static function fromProfile(
		AtprotoProfile $profile,
		?AtprotoBlob $avatar,
		?AtprotoBlob $banner,
		DateTimeImmutable $createdAt,
	): array {
		$record = [
			'$type' => self::COLLECTION,
			'createdAt' => Time::format($createdAt),
		];

		if ($profile->displayName !== null) {
			$record['displayName'] = $profile->displayName;
		}

		if ($profile->description !== null) {
			$record['description'] = $profile->description;
		}

		if ($avatar !== null) {
			$record['avatar'] = $avatar->toArray();
		}

		if ($banner !== null) {
			$record['banner'] = $banner->toArray();
		}

		return $record;
	}
}
