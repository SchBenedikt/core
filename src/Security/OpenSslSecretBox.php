<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Security;

/**
 * AES-256-GCM with a random nonce per payload.
 *
 * Payload layout, base64 encoded: version byte, 12-byte nonce, 16-byte tag,
 * ciphertext. The additional authenticated data is a fixed label, so a blob
 * sealed for another purpose cannot be passed off as one of these.
 */
final class OpenSslSecretBox implements SecretBox
{
	private const CIPHER = 'aes-256-gcm';
	private const KEY_BYTES = 32;
	private const NONCE_BYTES = 12;
	private const TAG_BYTES = 16;
	private const VERSION = "\x01";
	private const AAD = 'alohasocial.core.secretbox.v1';

	private function __construct(
		private readonly string $key,
	) {
	}

	/**
	 * @param string $encoded 32 raw bytes, base64 encoded
	 */
	public static function fromBase64Key(string $encoded): self
	{
		$key = base64_decode(trim($encoded), true);
		if ($key === false || strlen($key) !== self::KEY_BYTES) {
			throw new SecretBoxException('A secret box key must be ' . self::KEY_BYTES . ' bytes, base64 encoded.');
		}

		return new self($key);
	}

	public static function generateKey(): string
	{
		return base64_encode(random_bytes(self::KEY_BYTES));
	}

	public function seal(string $plaintext): string
	{
		$nonce = random_bytes(self::NONCE_BYTES);
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			self::AAD,
			self::TAG_BYTES,
		);

		if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
			throw new SecretBoxException('The secret could not be sealed.');
		}

		return base64_encode(self::VERSION . $nonce . $tag . $ciphertext);
	}

	public function open(string $payload): string
	{
		$raw = base64_decode(trim($payload), true);
		if ($raw === false || strlen($raw) < 1 + self::NONCE_BYTES + self::TAG_BYTES) {
			throw new SecretBoxException('The payload is not a sealed secret.');
		}

		if ($raw[0] !== self::VERSION) {
			throw new SecretBoxException('Unsupported payload version.');
		}

		$nonce = substr($raw, 1, self::NONCE_BYTES);
		$tag = substr($raw, 1 + self::NONCE_BYTES, self::TAG_BYTES);
		$ciphertext = substr($raw, 1 + self::NONCE_BYTES + self::TAG_BYTES);

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			self::AAD,
		);

		if ($plaintext === false) {
			throw new SecretBoxException('The payload cannot be opened with this key.');
		}

		return $plaintext;
	}
}
