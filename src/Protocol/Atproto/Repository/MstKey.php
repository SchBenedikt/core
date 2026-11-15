<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;

/**
 * Repository record paths and their MST layer.
 *
 * A key is always `<collection>/<rkey>`. The layer is the number of leading
 * two-bit zero pairs in SHA-256(key), capped by the number of hash bits. This
 * is the same fan-out rule the AT Protocol reference implementation uses.
 */
final class MstKey
{
	public const MAX_LENGTH = 1024;

	private const PART_PATTERN = '/^[a-zA-Z0-9_~\-:.]+$/';

	private function __construct()
	{
	}

	public static function assertValid(string $key): void
	{
		if ($key === '' || strlen($key) > self::MAX_LENGTH) {
			throw new InvalidArgumentException('An MST key must contain 1 to 1024 bytes.');
		}

		$parts = explode('/', $key);
		if (count($parts) !== 2
			|| $parts[0] === ''
			|| $parts[1] === ''
			|| preg_match(self::PART_PATTERN, $parts[0]) !== 1
			|| preg_match(self::PART_PATTERN, $parts[1]) !== 1) {
			throw new InvalidArgumentException('An MST key must be a valid collection/rkey path.');
		}
	}

	public static function layer(string $key): int
	{
		self::assertValid($key);
		$hash = hash('sha256', $key, true);
		$leadingZeros = 0;

		foreach (str_split($hash) as $character) {
			$byte = ord($character);
			if ($byte < 64) {
				++$leadingZeros;
			}
			if ($byte < 16) {
				++$leadingZeros;
			}
			if ($byte < 4) {
				++$leadingZeros;
			}
			if ($byte === 0) {
				++$leadingZeros;
				continue;
			}

			break;
		}

		return $leadingZeros;
	}

	public static function commonPrefixLength(string $left, string $right): int
	{
		$limit = min(strlen($left), strlen($right));

		for ($index = 0; $index < $limit; ++$index) {
			if ($left[$index] !== $right[$index]) {
				return $index;
			}
		}

		return $limit;
	}
}
