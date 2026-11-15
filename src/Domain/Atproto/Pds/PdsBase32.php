<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

/**
 * Lowercase base32 used by did:plc identifiers.
 */
final class PdsBase32
{
	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	private function __construct()
	{
	}

	public static function encode(string $bytes): string
	{
		$encoded = '';
		$buffer = 0;
		$bits = 0;

		foreach (str_split($bytes) as $character) {
			$buffer = ($buffer << 8) | ord($character);
			$bits += 8;

			while ($bits >= 5) {
				$bits -= 5;
				$encoded .= self::ALPHABET[($buffer >> $bits) & 31];
			}

			$buffer &= (1 << $bits) - 1;
		}

		if ($bits > 0) {
			$encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
		}

		return $encoded;
	}
}
