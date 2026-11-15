<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;

/**
 * Unsigned LEB128 integers used by CID and CAR framing.
 *
 * The decoder rejects non-minimal encodings. A second encoding of the same
 * value would otherwise produce a different byte stream for the same block,
 * which breaks content addressing and makes malformed-input tests ambiguous.
 */
final class Varint
{
	private function __construct()
	{
	}

	public static function encode(int $value): string
	{
		if ($value < 0) {
			throw new InvalidArgumentException('A varint cannot be negative.');
		}

		$encoded = '';
		do {
			$byte = $value & 0x7f;
			$value >>= 7;
			if ($value !== 0) {
				$byte |= 0x80;
			}
			$encoded .= chr($byte);
		} while ($value !== 0);

		return $encoded;
	}

	public static function decode(string $bytes, int &$offset): int
	{
		$start = $offset;
		$value = 0;
		$shift = 0;

		for ($index = 0; $index < 8; ++$index) {
			if ($offset >= strlen($bytes)) {
				throw new InvalidArgumentException('The varint is truncated.');
			}

			$byte = ord($bytes[$offset++]);
			$value |= ($byte & 0x7f) << $shift;

			if (($byte & 0x80) === 0) {
				if ($index > 0 && ($byte & 0x7f) === 0) {
					throw new InvalidArgumentException('The varint is not minimally encoded.');
				}

				return $value;
			}

			$shift += 7;
		}

		throw new InvalidArgumentException('The varint is too long at offset ' . $start . '.');
	}
}
