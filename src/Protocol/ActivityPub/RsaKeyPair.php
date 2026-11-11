<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * An actor's RSA key pair.
 *
 * 2048 bits is the floor ActivityPub implementations accept and what
 * Mastodon generates; a smaller key is refused rather than silently used.
 */
final readonly class RsaKeyPair
{
	public const MIN_BITS = 2048;
	public const DEFAULT_BITS = 2048;

	private function __construct(
		private string $privateKeyPem,
		private string $publicKeyPem,
	) {
	}

	public static function generate(int $bits = self::DEFAULT_BITS): self
	{
		if ($bits < self::MIN_BITS) {
			throw new InvalidArgumentException('An RSA key must have at least ' . self::MIN_BITS . ' bits.');
		}

		$key = openssl_pkey_new([
			'private_key_bits' => $bits,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);

		if (!$key instanceof OpenSSLAsymmetricKey) {
			throw SignatureException::key(self::lastError('generation failed'));
		}

		$privateKeyPem = '';
		if (!openssl_pkey_export($key, $privateKeyPem)) {
			throw SignatureException::key(self::lastError('the private key could not be exported'));
		}

		$details = openssl_pkey_get_details($key);
		if ($details === false || !isset($details['key']) || !is_string($details['key'])) {
			throw SignatureException::key(self::lastError('the public key could not be read'));
		}

		return new self($privateKeyPem, $details['key']);
	}

	public static function fromPems(string $privateKeyPem, string $publicKeyPem): self
	{
		if (openssl_pkey_get_private($privateKeyPem) === false) {
			throw SignatureException::key(self::lastError('not a usable private key'));
		}

		if (openssl_pkey_get_public($publicKeyPem) === false) {
			throw SignatureException::key(self::lastError('not a usable public key'));
		}

		return new self($privateKeyPem, $publicKeyPem);
	}

	public function privateKeyPem(): string
	{
		return $this->privateKeyPem;
	}

	public function publicKeyPem(): string
	{
		return $this->publicKeyPem;
	}

	/**
	 * The raw RSA-SHA256 signature.
	 */
	public function sign(string $data): string
	{
		$key = openssl_pkey_get_private($this->privateKeyPem);
		if ($key === false) {
			throw SignatureException::key(self::lastError('the private key is not usable'));
		}

		$signature = '';
		if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
			throw SignatureException::key(self::lastError('signing failed'));
		}

		return $signature;
	}

	public function verify(string $data, string $signature): bool
	{
		return openssl_verify($data, $signature, $this->publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
	}

	private static function lastError(string $fallback): string
	{
		$error = openssl_error_string();

		return $error === false ? $fallback : $error;
	}
}
