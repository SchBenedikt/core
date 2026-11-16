<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

/**
 * Metadata for one immutable blob stored by a native PDS.
 *
 * The bytes live in the host's BlobStore; this value only records the
 * repository owner, content address, media type and size.
 */
final readonly class PdsBlob
{
	public function __construct(
		public string $did,
		public string $cid,
		public string $mimeType,
		public int $size,
		public string $createdAt,
	) {
		if ($did === '' || $cid === '' || $mimeType === '') {
			throw new \InvalidArgumentException('A PDS blob needs a DID, CID and media type.');
		}

		if ($size < 0) {
			throw new \InvalidArgumentException('A PDS blob size cannot be negative.');
		}
	}
}
