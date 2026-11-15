<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use InvalidArgumentException;
use RuntimeException;

/**
 * Generates the P-256 key material a PLC identity needs.
 *
 * Private keys never leave the core unsealed: the caller immediately encrypts
 * the PEM value with its SecretBox and stores only the sealed form.
 */
final class PdsKeyGenerator
{
	private const P256_PUBLIC_CODEC = "\x80\x24";

	private function __construct()
	{
	}

	/**
	 * @return array{private: string, public: string, multibase: string}
	 */
	public static function generate(): array
	{
		$key = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		]);
		if ($key === false || !openssl_pkey_export($key, $private)) {
			throw new RuntimeException('A P-256 key pair could not be generated.');
		}

		$details = openssl_pkey_get_details($key);
		if ($details === false || !isset($details['key'], $details['ec']['x'], $details['ec']['y'])) {
			throw new RuntimeException('The generated P-256 public key could not be read.');
		}

		$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
		$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
		$prefix = (ord($y[31]) & 1) === 1 ? "\x03" : "\x02";
		$compressed = $prefix . $x;

		return [
			'private' => $private,
			'public' => $details['key'],
			'multibase' => 'z' . PdsBase58::encode(self::P256_PUBLIC_CODEC . $compressed),
		];
	}

	public static function assertP256Pem(string $privateKeyPem): void
	{
		$key = openssl_pkey_get_private($privateKeyPem);
		if ($key === false) {
			throw new InvalidArgumentException('The PDS private key is not valid PEM.');
		}

		$details = openssl_pkey_get_details($key);
		if ($details === false || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
			throw new InvalidArgumentException('The PDS key must use the prime256v1 curve.');
		}
	}
}
