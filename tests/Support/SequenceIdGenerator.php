<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Support\IdGenerator;

/**
 * Predictable ids, so a test can name what it expects.
 */
final class SequenceIdGenerator implements IdGenerator
{
	private int $next = 1;

	public function __construct(
		private readonly string $prefix = 'id-',
	) {
	}

	public function generate(): string
	{
		return $this->prefix . $this->next++;
	}
}
