<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto\Repository;

/**
 * One leaf of an MST node, reconstructed from its prefix-compacted form.
 */
final readonly class MstEntry
{
	public function __construct(
		public string $key,
		public Cid $value,
		public ?Cid $right,
	) {
	}
}
