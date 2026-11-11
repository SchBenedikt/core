<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A clock a test can move by hand.
 *
 * Deliberately mutable: a test sets the instant once, then advances it to
 * cross a retry window or an expiry boundary.
 */
final class FrozenClock implements Clock
{
	private DateTimeImmutable $now;

	public function __construct(?DateTimeImmutable $now = null)
	{
		$this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->setTimezone(new DateTimeZone('UTC'));
	}

	public function now(): DateTimeImmutable
	{
		return $this->now;
	}

	public function set(DateTimeImmutable $now): void
	{
		$this->now = $now->setTimezone(new DateTimeZone('UTC'));
	}

	/**
	 * @throws InvalidArgumentException when the offset would move time backwards
	 */
	public function advance(int $seconds): void
	{
		if ($seconds < 0) {
			throw new InvalidArgumentException('A frozen clock only moves forward.');
		}

		$this->now = $this->now->modify('+' . $seconds . ' seconds');
	}
}
