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
 * One record read back from a PDS.
 *
 * `cid` is the content hash a reply reference must carry; the record value is
 * needed to inherit the root of a parent that is itself a reply.
 */
final readonly class AtprotoRecord
{
	public const MAX_CID_LENGTH = 256;

	/**
	 * @param array<string, mixed> $record
	 */
	public function __construct(
		public string $uri,
		public string $cid,
		public array $record,
	) {
		if (!str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new InvalidArgumentException('An AT Protocol record needs a valid AT URI.');
		}

		if ($cid === '' || trim($cid) !== $cid || strlen($cid) > self::MAX_CID_LENGTH) {
			throw new InvalidArgumentException('An AT Protocol record needs a CID.');
		}
	}
}
