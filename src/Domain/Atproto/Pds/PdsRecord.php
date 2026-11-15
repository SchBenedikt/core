<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;

final readonly class PdsRecord
{
	public function __construct(
		public string $did,
		public string $collection,
		public string $rkey,
		public Cid $cid,
		public string $cbor,
		public string $createdAt,
		public string $updatedAt,
	) {
	}

	public function uri(): string
	{
		return 'at://' . $this->did . '/' . $this->collection . '/' . $this->rkey;
	}
}
