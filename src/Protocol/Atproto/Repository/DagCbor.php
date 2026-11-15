<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;

/**
 * Canonical DAG-CBOR for AT Protocol repository blocks.
 *
 * Only the data model used by repositories is accepted: strings, byte strings,
 * integers, booleans, null, arrays, maps and CID links. Floats, indefinite
 * lengths and unknown tags are refused because they cannot be represented
 * deterministically across implementations.
 */
final class DagCbor
{
	private const MAX_DEPTH = 64;

	private const MAX_CONTAINER_ITEMS = 1_000_000;

	private const CID_LINK_TAG = 42;

	private function __construct()
	{
	}

	public static function encode(mixed $value): string
	{
		return self::encodeValue($value, 0);
	}

	public static function decode(string $bytes): mixed
	{
		$offset = 0;
		$value = self::decodeValue($bytes, $offset, 0);

		if ($offset !== strlen($bytes)) {
			throw new InvalidArgumentException('Trailing bytes follow the DAG-CBOR value.');
		}

		return $value;
	}

	private static function encodeValue(mixed $value, int $depth): string
	{
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('The DAG-CBOR value is nested too deeply.');
		}

		if ($value === null) {
			return "\xf6";
		}

		if ($value === true) {
			return "\xf5";
		}

		if ($value === false) {
			return "\xf4";
		}

		if ($value instanceof Cid) {
			return self::head(6, self::CID_LINK_TAG) . self::bytes("\x00" . $value->bytes());
		}

		if ($value instanceof Bytes) {
			return self::bytes($value->value);
		}

		if (is_int($value)) {
			if ($value >= 0) {
				return self::head(0, $value);
			}

			return self::head(1, -1 - $value);
		}

		if (is_string($value)) {
			return self::text($value);
		}

		if (is_array($value)) {
			if (array_is_list($value)) {
				$encoded = self::head(4, count($value));
				foreach ($value as $item) {
					$encoded .= self::encodeValue($item, $depth + 1);
				}

				return $encoded;
			}

			$pairs = [];
			foreach ($value as $key => $item) {
				if (!is_string($key)) {
					throw new InvalidArgumentException('DAG-CBOR map keys must be strings.');
				}

				$pairs[] = [$key, $item];
			}

			usort(
				$pairs,
				static fn (array $left, array $right): int => (strlen($left[0]) <=> strlen($right[0]))
					?: strcmp($left[0], $right[0]),
			);

			$encoded = self::head(5, count($pairs));
			foreach ($pairs as [$key, $item]) {
				$encoded .= self::text($key) . self::encodeValue($item, $depth + 1);
			}

			return $encoded;
		}

		throw new InvalidArgumentException('Unsupported DAG-CBOR value of type ' . get_debug_type($value) . '.');
	}

	private static function decodeValue(string $bytes, int &$offset, int $depth): mixed
	{
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('The DAG-CBOR value is nested too deeply.');
		}

		if ($offset >= strlen($bytes)) {
			throw new InvalidArgumentException('The DAG-CBOR value is truncated.');
		}

		$initial = ord($bytes[$offset++]);
		$major = $initial >> 5;
		$additional = $initial & 31;

		if ($major === 7) {
			return match ($additional) {
				20 => false,
				21 => true,
				22 => null,
				default => throw new InvalidArgumentException('Unsupported DAG-CBOR simple value.'),
			};
		}

		$length = self::readLength($bytes, $offset, $additional);

		return match ($major) {
			0 => $length,
			1 => $length === PHP_INT_MAX ? PHP_INT_MIN : -1 - $length,
			2 => new Bytes(self::readBytes($bytes, $offset, $length)),
			3 => self::readText($bytes, $offset, $length),
			4 => self::readArray($bytes, $offset, $length, $depth),
			5 => self::readMap($bytes, $offset, $length, $depth),
			6 => self::readTag($bytes, $offset, $length, $depth),
			default => throw new InvalidArgumentException('Unsupported DAG-CBOR major type.'),
		};
	}

	private static function readTag(string $bytes, int &$offset, int $tag, int $depth): Cid
	{
		if ($tag !== self::CID_LINK_TAG) {
			throw new InvalidArgumentException('Unsupported DAG-CBOR tag ' . $tag . '.');
		}

		if ($offset >= strlen($bytes)) {
			throw new InvalidArgumentException('The DAG-CBOR CID link is truncated.');
		}

		$initial = ord($bytes[$offset++]);
		if (($initial >> 5) !== 2) {
			throw new InvalidArgumentException('A DAG-CBOR CID link must contain a byte string.');
		}

		$length = self::readLength($bytes, $offset, $initial & 31);
		$payload = self::readBytes($bytes, $offset, $length);
		if ($payload === '' || $payload[0] !== "\x00") {
			throw new InvalidArgumentException('A DAG-CBOR CID link is missing its identity prefix.');
		}

		return Cid::fromBytes(substr($payload, 1));
	}

	private static function readBytes(string $bytes, int &$offset, int $length): string
	{
		if ($length > strlen($bytes) - $offset) {
			throw new InvalidArgumentException('The DAG-CBOR byte string is truncated.');
		}

		$value = substr($bytes, $offset, $length);
		$offset += $length;

		return $value;
	}

	private static function readText(string $bytes, int &$offset, int $length): string
	{
		$value = self::readBytes($bytes, $offset, $length);
		if (preg_match('//u', $value) !== 1) {
			throw new InvalidArgumentException('The DAG-CBOR text string is not valid UTF-8.');
		}

		return $value;
	}

	private static function readArray(string $bytes, int &$offset, int $length, int $depth): array
	{
		if ($length > self::MAX_CONTAINER_ITEMS) {
			throw new InvalidArgumentException('The DAG-CBOR array is too large.');
		}

		$value = [];
		for ($index = 0; $index < $length; ++$index) {
			$value[] = self::decodeValue($bytes, $offset, $depth + 1);
		}

		return $value;
	}

	private static function readMap(string $bytes, int &$offset, int $length, int $depth): array
	{
		if ($length > self::MAX_CONTAINER_ITEMS) {
			throw new InvalidArgumentException('The DAG-CBOR map is too large.');
		}

		$value = [];
		$previousKey = null;

		for ($index = 0; $index < $length; ++$index) {
			if ($offset >= strlen($bytes)) {
				throw new InvalidArgumentException('The DAG-CBOR map key is truncated.');
			}

			$initial = ord($bytes[$offset++]);
			if (($initial >> 5) !== 3) {
				throw new InvalidArgumentException('DAG-CBOR map keys must be text strings.');
			}

			$keyLength = self::readLength($bytes, $offset, $initial & 31);
			$key = self::readText($bytes, $offset, $keyLength);

			if ($previousKey !== null
				&& ((strlen($previousKey) > strlen($key))
					|| (strlen($previousKey) === strlen($key) && strcmp($previousKey, $key) >= 0))) {
				throw new InvalidArgumentException('DAG-CBOR map keys are not in canonical order.');
			}

			if (array_key_exists($key, $value)) {
				throw new InvalidArgumentException('DAG-CBOR map keys are not unique.');
			}

			$value[$key] = self::decodeValue($bytes, $offset, $depth + 1);
			$previousKey = $key;
		}

		return $value;
	}

	private static function readLength(string $bytes, int &$offset, int $additional): int
	{
		if ($additional < 24) {
			return $additional;
		}

		$width = match ($additional) {
			24 => 1,
			25 => 2,
			26 => 4,
			27 => 8,
			default => throw new InvalidArgumentException('Indefinite or reserved DAG-CBOR lengths are not supported.'),
		};

		if ($width > strlen($bytes) - $offset) {
			throw new InvalidArgumentException('The DAG-CBOR length is truncated.');
		}

		$value = 0;
		for ($index = 0; $index < $width; ++$index) {
			if ($value > (PHP_INT_MAX >> 8)) {
				throw new InvalidArgumentException('The DAG-CBOR length exceeds the PHP integer range.');
			}

			$value = ($value << 8) | ord($bytes[$offset++]);
		}

		if ($width === 8 && $value < 0) {
			throw new InvalidArgumentException('The DAG-CBOR length exceeds the PHP integer range.');
		}

		return $value;
	}

	private static function head(int $major, int $value): string
	{
		if ($value < 24) {
			return chr(($major << 5) | $value);
		}

		if ($value <= 0xff) {
			return chr(($major << 5) | 24) . chr($value);
		}

		if ($value <= 0xffff) {
			return chr(($major << 5) | 25) . pack('n', $value);
		}

		if ($value <= 0xffffffff) {
			return chr(($major << 5) | 26) . pack('N', $value);
		}

		return chr(($major << 5) | 27) . pack('J', $value);
	}

	private static function text(string $value): string
	{
		if (preg_match('//u', $value) !== 1) {
			throw new InvalidArgumentException('DAG-CBOR text must be valid UTF-8.');
		}

		return self::head(3, strlen($value)) . $value;
	}

	private static function bytes(string $value): string
	{
		return self::head(2, strlen($value)) . $value;
	}
}
