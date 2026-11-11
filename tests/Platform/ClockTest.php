<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform;

use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
	public function testSystemClockAnswersInUtc(): void
	{
		$now = (new SystemClock())->now();

		self::assertSame('UTC', $now->getTimezone()->getName());
	}

	public function testSystemClockIsCloseToTheRealTime(): void
	{
		$before = time();
		$now = (new SystemClock())->now()->getTimestamp();

		self::assertGreaterThanOrEqual($before, $now);
		self::assertLessThanOrEqual($before + 2, $now);
	}

	public function testFrozenClockStartsAtItsInstant(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));

		self::assertSame('2026-11-11T10:00:00Z', Time::format($clock->now()));
	}

	public function testFrozenClockMovesForward(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));

		$clock->advance(90);

		self::assertSame('2026-11-11T10:01:30Z', Time::format($clock->now()));
	}

	public function testFrozenClockCanBeSet(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));

		$clock->set(Time::parse('2027-01-01T00:00:00Z'));

		self::assertSame('2027-01-01T00:00:00Z', Time::format($clock->now()));
	}

	public function testFrozenClockRefusesToGoBackwards(): void
	{
		$clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));

		$this->expectException(InvalidArgumentException::class);

		$clock->advance(-1);
	}

	public function testFrozenClockNormalisesItsStartToUtc(): void
	{
		$clock = new FrozenClock(new \DateTimeImmutable('2026-11-11T12:00:00+02:00'));

		self::assertSame('2026-11-11T10:00:00Z', Time::format($clock->now()));
	}
}
