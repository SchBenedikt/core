<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;

final readonly class PdsCommit
{
	public function __construct(
		public string $did,
		public Cid $cid,
		public int $seq,
		public string $rev,
		public Cid $dataCid,
		public ?Cid $previousCid,
		public string $signingKeyId,
		public string $bytes,
		public string $createdAt,
	) {
	}
}
