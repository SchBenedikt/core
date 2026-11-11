<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TimeTest extends TestCase
{
	public function testFormatDropsMicroseconds(): void
	{
		$time = new DateTimeImmutable('2026-11-11 10:00:00.654321', new DateTimeZone('UTC'));

		self::assertSame('2026-11-11T10:00:00Z', Time::format($time));
	}

	public function testFormatConvertsToUtc(): void
	{
		$time = new DateTimeImmutable('2026-11-11T12:00:00+02:00');

		self::assertSame('2026-11-11T10:00:00Z', Time::format($time));
	}

	public function testParseRoundTrips(): void
	{
		$parsed = Time::parse('2026-11-11T10:00:00Z');

		self::assertSame('2026-11-11T10:00:00Z', Time::format($parsed));
		self::assertSame('UTC', $parsed->getTimezone()->getName());
	}

	public function testParseRejectsANonCanonicalForm(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Time::parse('2026-11-11T10:00:00+00:00');
	}

	public function testParseRejectsAnImpossibleDate(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Time::parse('2026-02-31T00:00:00Z');
	}

	public function testHttpDateIsImfFixdate(): void
	{
		self::assertSame(
			'Wed, 11 Nov 2026 10:00:00 GMT',
			Time::httpDate(Time::parse('2026-11-11T10:00:00Z')),
		);
	}
}
