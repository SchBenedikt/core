<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

/**
 * A clock for tests that need several distinct post timestamps.
 */
final class MutableClock implements Clock
{
	private DateTimeImmutable $current;

	public function __construct(string $start = '2026-11-11T10:00:00Z')
	{
		$this->current = Time::parse($start);
	}

	public function now(): DateTimeImmutable
	{
		return $this->current;
	}

	public function advance(int $seconds): void
	{
		$this->current = $this->current->modify('+' . max(0, $seconds) . ' seconds');
	}
}
