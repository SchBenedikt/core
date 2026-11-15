<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;

final readonly class PdsPlcOperation
{
	public function __construct(
		public string $did,
		public Cid $cid,
		public int $seq,
		public string $bytes,
		public string $createdAt,
	) {
	}
}
