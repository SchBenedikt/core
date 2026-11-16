<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;

/**
 * A CIDv1 with the dag-cbor codec and a SHA-256 multihash.
 *
 * AT Protocol repository blocks are always addressed this way. Keeping the
 * value object this narrow means a caller cannot accidentally produce a valid
 * IPFS CID that the repository layer cannot read.
 */
final readonly class Cid
{
	private const VERSION = 1;

	private const DAG_CBOR_CODEC = 0x71;

	private const RAW_CODEC = 0x55;

	private const SHA2_256 = 0x12;

	private const DIGEST_LENGTH = 32;

	private const BASE32_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	private function __construct(private string $bytes)
	{
	}

	public static function forData(string $data): self
	{
		return self::forCodec(self::DAG_CBOR_CODEC, $data);
	}

	/**
	 * A raw-codec CID for bytes that are not DAG-CBOR, such as a PDS blob.
	 */
	public static function forBlob(string $data): self
	{
		return self::forCodec(self::RAW_CODEC, $data);
	}

	public static function fromString(string $value): self
	{
		return self::fromBytes(self::decodeMultibase($value));
	}

	/**
	 * Parses a raw-codec CID, as used by a PDS blob reference.
	 */
	public static function fromBlobString(string $value): self
	{
		return self::fromBytes(self::decodeMultibase($value), true);
	}

	public static function fromBytes(string $bytes, bool $allowRaw = false): self
	{
		$offset = 0;
		$version = Varint::decode($bytes, $offset);
		$codec = Varint::decode($bytes, $offset);
		$hash = Varint::decode($bytes, $offset);
		$length = Varint::decode($bytes, $offset);

		if ($version !== self::VERSION
			|| (!$allowRaw && $codec !== self::DAG_CBOR_CODEC)
			|| ($allowRaw && $codec !== self::DAG_CBOR_CODEC && $codec !== self::RAW_CODEC)
			|| $hash !== self::SHA2_256
			|| $length !== self::DIGEST_LENGTH) {
			throw new InvalidArgumentException('The CID is not a CIDv1 dag-cbor SHA-256 value.');
		}

		if ($offset + $length !== strlen($bytes)) {
			throw new InvalidArgumentException('The CID digest length does not match its bytes.');
		}

		return new self($bytes);
	}

	/**
	 * Reads one CID at the current offset and advances it.
	 */
	public static function readFrom(string $bytes, int &$offset): self
	{
		$start = $offset;
		$version = Varint::decode($bytes, $offset);
		$codec = Varint::decode($bytes, $offset);
		$hash = Varint::decode($bytes, $offset);
		$length = Varint::decode($bytes, $offset);

		if ($version !== self::VERSION
			|| $codec !== self::DAG_CBOR_CODEC
			|| $hash !== self::SHA2_256
			|| $length !== self::DIGEST_LENGTH) {
			throw new InvalidArgumentException('The CID is not a CIDv1 dag-cbor SHA-256 value.');
		}

		$end = $offset + $length;
		if ($end > strlen($bytes)) {
			throw new InvalidArgumentException('The CID digest is truncated.');
		}

		$offset = $end;

		return new self(substr($bytes, $start, $end - $start));
	}

	public function bytes(): string
	{
		return $this->bytes;
	}

	public function toString(): string
	{
		return 'b' . self::base32Encode($this->bytes);
	}

	public function equals(self $other): bool
	{
		return $this->bytes === $other->bytes;
	}

	public function __toString(): string
	{
		return $this->toString();
	}

	private static function forCodec(int $codec, string $data): self
	{
		$bytes = Varint::encode(self::VERSION)
			. Varint::encode($codec)
			. Varint::encode(self::SHA2_256)
			. Varint::encode(self::DIGEST_LENGTH)
			. hash('sha256', $data, true);

		return $codec === self::DAG_CBOR_CODEC ? self::fromBytes($bytes) : new self($bytes);
	}

	private static function decodeMultibase(string $value): string
	{
		if ($value === '' || $value[0] !== 'b') {
			throw new InvalidArgumentException('A repository CID must use lowercase base32 multibase.');
		}

		return self::base32Decode(substr($value, 1));
	}

	private static function base32Encode(string $bytes): string
	{
		$encoded = '';
		$buffer = 0;
		$bits = 0;

		foreach (str_split($bytes) as $character) {
			$buffer = ($buffer << 8) | ord($character);
			$bits += 8;

			while ($bits >= 5) {
				$bits -= 5;
				$encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
			}

			$buffer &= (1 << $bits) - 1;
		}

		if ($bits > 0) {
			$encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
		}

		return $encoded;
	}

	private static function base32Decode(string $encoded): string
	{
		if ($encoded === '') {
			throw new InvalidArgumentException('The base32 CID payload is empty.');
		}

		$decoded = '';
		$buffer = 0;
		$bits = 0;

		foreach (str_split($encoded) as $character) {
			$value = strpos(self::BASE32_ALPHABET, $character);
			if ($value === false) {
				throw new InvalidArgumentException('The CID contains a non-base32 character.');
			}

			$buffer = ($buffer << 5) | $value;
			$bits += 5;

			if ($bits >= 8) {
				$bits -= 8;
				$decoded .= chr(($buffer >> $bits) & 0xff);
			}

			$buffer &= (1 << $bits) - 1;
		}

		if ($bits > 0 && $buffer !== 0) {
			throw new InvalidArgumentException('The CID has non-zero base32 padding bits.');
		}

		return $decoded;
	}
}
