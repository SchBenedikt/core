<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Timestamp identifiers used as AT Protocol record keys.
 *
 * The value is a 64-bit integer: one zero top bit, 53 bits of microseconds
 * since the epoch and 10 bits of clock id, encoded as 13 base32-sortable
 * characters. The clock id is derived from the seed so a retry for the same
 * local post produces the same record key; the sub-second offset also comes
 * from the seed, which keeps two posts from the same second apart without a
 * mutable counter.
 */
final class Tid
{
	public const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

	private const CLOCK_BITS = 10;

	private const CLOCK_MASK = (1 << self::CLOCK_BITS) - 1;

	private const MICROSECONDS_PER_SECOND = 1_000_000;

	private function __construct()
	{
	}

	/**
	 * A deterministic TID for a record.
	 *
	 * AT Protocol treats rkeys as user-controlled and does not require a TID
	 * timestamp to be the actual creation time. Reusing the same value on a
	 * retry is what makes `putRecord` idempotent.
	 */
	public static function forRecord(DateTimeImmutable $time, string $seed): string
	{
		if ($seed === '') {
			throw new InvalidArgumentException('A TID seed is required.');
		}

		$seconds = (int) $time->format('U');
		$offset = (int) (crc32($seed) % self::MICROSECONDS_PER_SECOND);
		$microseconds = ($seconds * self::MICROSECONDS_PER_SECOND) + $offset;
		$clock = (int) (crc32('clock:' . $seed) & self::CLOCK_MASK);
		$value = (($microseconds & ((1 << 53) - 1)) << self::CLOCK_BITS) | $clock;

		$result = '';
		for ($position = 12; $position >= 0; --$position) {
			$result .= self::ALPHABET[($value >> ($position * 5)) & 31];
		}

		return $result;
	}

	public static function isValid(string $value): bool
	{
		return preg_match('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/', $value) === 1;
	}
}
