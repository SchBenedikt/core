<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * P-256 ECDSA signatures in the raw 64-byte form used by repository commits.
 *
 * PHP's OpenSSL extension signs DER-encoded ECDSA values. AT Protocol commits
 * carry the fixed-width r||s form, so the conversion is kept in one place and
 * every malformed DER value is refused rather than truncated.
 */
final class Ecdsa
{
	public const SIGNATURE_BYTES = 64;

	private function __construct()
	{
	}

	public static function sign(string $message, string $privateKeyPem): string
	{
		$key = openssl_pkey_get_private($privateKeyPem);
		if ($key === false) {
			throw new InvalidArgumentException('The commit private key is not a valid PEM key.');
		}

		if (!openssl_sign($message, $der, $key, OPENSSL_ALGO_SHA256)) {
			throw new RuntimeException('The commit could not be signed.');
		}

		return self::derToRaw($der);
	}

	public static function verify(string $message, string $signature, string $publicKeyPem): bool
	{
		if (strlen($signature) !== self::SIGNATURE_BYTES) {
			return false;
		}

		$key = openssl_pkey_get_public($publicKeyPem);
		if ($key === false) {
			return false;
		}

		return openssl_verify($message, self::rawToDer($signature), $key, OPENSSL_ALGO_SHA256) === 1;
	}

	private static function derToRaw(string $der): string
	{
		if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
			throw new UnexpectedValueException('The ECDSA signature is not a DER sequence.');
		}

		$sequenceLength = ord($der[1]);
		if (($sequenceLength & 0x80) !== 0 || 2 + $sequenceLength !== strlen($der)) {
			throw new UnexpectedValueException('The ECDSA signature has an invalid sequence length.');
		}

		$offset = 2;
		$parts = [];
		for ($part = 0; $part < 2; ++$part) {
			if ($offset + 2 > strlen($der) || ord($der[$offset]) !== 0x02) {
				throw new UnexpectedValueException('The ECDSA signature is missing an integer.');
			}

			$length = ord($der[$offset + 1]);
			$offset += 2;
			if ($length < 1 || $offset + $length > strlen($der)) {
				throw new UnexpectedValueException('The ECDSA signature integer is truncated.');
			}

			$value = ltrim(substr($der, $offset, $length), "\x00");
			$offset += $length;
			if (strlen($value) > 32) {
				throw new UnexpectedValueException('The ECDSA signature integer is too large.');
			}

			$parts[] = str_pad($value, 32, "\x00", STR_PAD_LEFT);
		}

		if ($offset !== strlen($der)) {
			throw new UnexpectedValueException('The ECDSA signature has trailing data.');
		}

		return $parts[0] . $parts[1];
	}

	private static function rawToDer(string $raw): string
	{
		$r = self::unsignedInteger(substr($raw, 0, 32));
		$s = self::unsignedInteger(substr($raw, 32, 32));
		$sequence = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;

		return "\x30" . chr(strlen($sequence)) . $sequence;
	}

	private static function unsignedInteger(string $value): string
	{
		$value = ltrim($value, "\x00");
		if ($value === '') {
			return "\x00";
		}

		if ((ord($value[0]) & 0x80) !== 0) {
			return "\x00" . $value;
		}

		return $value;
	}
}
