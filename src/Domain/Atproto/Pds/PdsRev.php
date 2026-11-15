<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Tid;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Repository revisions use the same 13-character sortable encoding as TIDs.
 *
 * The value is microseconds since the epoch shifted left by ten bits. The
 * service additionally clamps it above the previous revision, so two writes in
 * the same microsecond still produce a strictly increasing rev.
 */
final class PdsRev
{
	private function __construct()
	{
	}

	public static function forTime(DateTimeImmutable $time): string
	{
		$microseconds = ((int) $time->format('U')) * 1_000_000 + ((int) $time->format('u'));

		return self::encode($microseconds);
	}

	public static function encode(int $microseconds): string
	{
		if ($microseconds < 0) {
			throw new InvalidArgumentException('A repository revision cannot be negative.');
		}

		$value = $microseconds << 10;
		$result = '';
		for ($position = 12; $position >= 0; --$position) {
			$result .= Tid::ALPHABET[($value >> ($position * 5)) & 31];
		}

		return $result;
	}

	public static function decode(string $rev): int
	{
		if (strlen($rev) !== 13) {
			throw new InvalidArgumentException('A repository revision must be 13 characters.');
		}

		$value = 0;
		for ($position = 0; $position < 13; ++$position) {
			$digit = strpos(Tid::ALPHABET, $rev[$position]);
			if ($digit === false) {
				throw new InvalidArgumentException('A repository revision contains an invalid character.');
			}

			$value = ($value << 5) | $digit;
		}

		return $value >> 10;
	}
}
