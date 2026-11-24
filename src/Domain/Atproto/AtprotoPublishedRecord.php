<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use InvalidArgumentException;

/** The stable URI and content revision returned by a successful PDS write. */
final readonly class AtprotoPublishedRecord
{
	public function __construct(public string $uri, public string $cid)
	{
		if (!AtprotoIdentifiers::isAtUri($uri)) {
			throw new InvalidArgumentException('A published AT Protocol record needs a valid AT URI.');
		}

		if ($cid === '' || trim($cid) !== $cid || strlen($cid) > 256) {
			throw new InvalidArgumentException('A published AT Protocol record needs a valid CID.');
		}
	}
}
