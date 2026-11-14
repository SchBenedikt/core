<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use InvalidArgumentException;

use function is_array;
use function strlen;
use function trim;

/**
 * A blob a PDS stored for one upload.
 *
 * The record embed references it as `{$type: 'blob', ref: {$link: <cid>},
 * mimeType, size}`; the CID is the only handle the AppView needs.
 */
final readonly class AtprotoBlob
{
	public const TYPE = 'blob';

	public const MAX_CID_LENGTH = 256;

	public function __construct(
		public string $cid,
		public string $mimeType,
		public int $size,
	) {
		if ($cid === '' || trim($cid) !== $cid || strlen($cid) > self::MAX_CID_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol blob needs a CID.');
		}

		if ($mimeType === '' || trim($mimeType) !== $mimeType || strlen($mimeType) > 128) {
			throw new InvalidArgumentException('An AT Protocol blob needs a MIME type.');
		}

		if ($size < 1) {
			throw new InvalidArgumentException('An AT Protocol blob needs a positive size.');
		}
	}

	/**
	 * @return array{
	 *     $type: string,
	 *     ref: array{$link: string},
	 *     mimeType: string,
	 *     size: int
	 * }
	 */
	public function toArray(): array
	{
		return [
			'$type' => self::TYPE,
			'ref' => ['$link' => $this->cid],
			'mimeType' => $this->mimeType,
			'size' => $this->size,
		];
	}

	/**
	 * Reads a blob reference from an XRPC response.
	 *
	 * A PDS answers `{"blob": {...}}`; the nested reference must carry a CID,
	 * otherwise the record would point at nothing and the upload is refused.
	 *
	 * @param array<string, mixed> $decoded
	 */
	public static function fromXrpcResponse(array $decoded, string $fallbackMimeType, int $fallbackSize): ?self
	{
		$blob = $decoded['blob'] ?? null;
		if (!is_array($blob)) {
			return null;
		}

		$ref = $blob['ref'] ?? null;
		$cid = is_array($ref) ? ($ref['$link'] ?? null) : null;
		if (!is_string($cid) || $cid === '') {
			return null;
		}

		$mimeType = $blob['mimeType'] ?? $fallbackMimeType;
		$size = $blob['size'] ?? $fallbackSize;
		if (!is_string($mimeType) || !is_int($size)) {
			return null;
		}

		try {
			return new self($cid, $mimeType, $size);
		} catch (InvalidArgumentException) {
			return null;
		}
	}
}
