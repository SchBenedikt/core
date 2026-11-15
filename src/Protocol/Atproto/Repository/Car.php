<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * CAR v1 archive of CID-addressed repository blocks.
 *
 * The header is a DAG-CBOR map with exactly one root. Decoding verifies the
 * CID digest of every block, rejects duplicates and requires the root block to
 * be present. Those checks make a malformed archive fail at the boundary
 * rather than during a later repository walk.
 */
final readonly class Car
{
	public const MAX_BLOCK_BYTES = 8_388_608;

	public const MAX_BLOCKS = 10_000;

	/**
	 * @param array<string, string> $blocks keyed by CID string
	 */
	public function __construct(
		public Cid $root,
		public array $blocks,
	) {
		if (!isset($blocks[$root->toString()])) {
			throw new InvalidArgumentException('A CAR archive must contain its root block.');
		}
	}

	public static function decode(string $car): self
	{
		$offset = 0;
		$headerLength = Varint::decode($car, $offset);
		if ($headerLength < 1 || $headerLength > strlen($car) - $offset) {
			throw new InvalidArgumentException('The CAR header length is invalid.');
		}

		$header = DagCbor::decode(substr($car, $offset, $headerLength));
		$offset += $headerLength;

		if (!is_array($header) || array_is_list($header)
			|| !array_key_exists('roots', $header)
			|| !array_key_exists('version', $header)
			|| $header['version'] !== 1) {
			throw new InvalidArgumentException('A CAR header must declare version 1 and roots.');
		}

		$roots = $header['roots'];
		if (!is_array($roots) || !array_is_list($roots) || count($roots) !== 1 || !$roots[0] instanceof Cid) {
			throw new InvalidArgumentException('A repository CAR archive must have exactly one root CID.');
		}

		$blocks = [];
		while ($offset < strlen($car)) {
			if (count($blocks) >= self::MAX_BLOCKS) {
				throw new InvalidArgumentException('The CAR archive contains too many blocks.');
			}

			$length = Varint::decode($car, $offset);
			if ($length < 1 || $length > self::MAX_BLOCK_BYTES) {
				throw new InvalidArgumentException('The CAR block length is invalid.');
			}

			if ($length > strlen($car) - $offset) {
				throw new InvalidArgumentException('The CAR block is truncated.');
			}

			$blockOffset = $offset;
			$cid = Cid::readFrom($car, $offset);
			$cidLength = $offset - $blockOffset;
			$dataLength = $length - $cidLength;
			if ($dataLength < 0 || $dataLength > strlen($car) - $offset) {
				throw new InvalidArgumentException('The CAR block CID does not fit its length.');
			}

			$data = substr($car, $offset, $dataLength);
			$offset += $dataLength;

			if (!Cid::forData($data)->equals($cid)) {
				throw new UnexpectedValueException('A CAR block does not match its CID.');
			}

			$key = $cid->toString();
			if (isset($blocks[$key])) {
				throw new InvalidArgumentException('The CAR archive contains a duplicate block.');
			}

			$blocks[$key] = $data;
		}

		return new self($roots[0], $blocks);
	}

	public function encode(): string
	{
		$header = DagCbor::encode(['roots' => [$this->root], 'version' => 1]);
		$car = Varint::encode(strlen($header)) . $header;

		foreach ($this->blocks as $key => $block) {
			$cid = Cid::fromString($key);
			$data = $block;
			if (strlen($data) > self::MAX_BLOCK_BYTES) {
				throw new InvalidArgumentException('A CAR block exceeds the maximum size.');
			}

			if (!Cid::forData($data)->equals($cid)) {
				throw new UnexpectedValueException('A CAR block does not match its CID.');
			}

			$payload = $cid->bytes() . $data;
			$car .= Varint::encode(strlen($payload)) . $payload;
		}

		return $car;
	}
}
