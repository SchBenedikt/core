<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use InvalidArgumentException;

/**
 * Base58btc without checksum, used inside did:key and Multikey values.
 */
final class PdsBase58
{
	private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	private function __construct()
	{
	}

	public static function encode(string $bytes): string
	{
		if ($bytes === '') {
			return '';
		}

		$digits = [];
		for ($offset = 0, $length = strlen($bytes); $offset < $length; ++$offset) {
			$carry = ord($bytes[$offset]);
			for ($index = 0, $count = count($digits); $index < $count; ++$index) {
				$carry += $digits[$index] << 8;
				$digits[$index] = $carry % 58;
				$carry = intdiv($carry, 58);
			}

			while ($carry > 0) {
				$digits[] = $carry % 58;
				$carry = intdiv($carry, 58);
			}
		}

		$encoded = str_repeat('1', strlen($bytes) - strlen(ltrim($bytes, "\x00")));
		for ($index = count($digits) - 1; $index >= 0; --$index) {
			$encoded .= self::ALPHABET[$digits[$index]];
		}

		return $encoded;
	}
}
