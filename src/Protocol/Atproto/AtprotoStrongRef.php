<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use InvalidArgumentException;

use function strlen;
use function trim;

/**
 * A `com.atproto.repo.strongRef`: a record URI plus its content CID.
 *
 * A like or repost points at the subject with both, because the AppView binds
 * the interaction to the exact version it saw. Guessing or omitting the CID
 * produces an interaction the network cannot attribute.
 */
final readonly class AtprotoStrongRef
{
	public function __construct(
		public string $uri,
		public string $cid,
	) {
		if (!str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new InvalidArgumentException('A strong reference needs a valid AT URI.');
		}

		if ($cid === '' || trim($cid) !== $cid || strlen($cid) > AtprotoRecord::MAX_CID_LENGTH) {
			throw new InvalidArgumentException('A strong reference needs a CID.');
		}
	}

	/**
	 * @return array{uri: string, cid: string}
	 */
	public function toArray(): array
	{
		return ['uri' => $this->uri, 'cid' => $this->cid];
	}
}
