<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Boost;

/**
 * The stored boost and whether this call created it.
 */
final readonly class BoostResult
{
	public function __construct(
		public Boost $boost,
		public bool $created,
	) {
	}
}
