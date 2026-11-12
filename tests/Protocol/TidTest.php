<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TidTest extends TestCase
{
	public function testItIsDeterministicAndValid(): void
	{
		$time = Time::parse('2026-11-11T10:00:00Z');

		$first = Tid::forRecord($time, 'post-1|did:plc:alice');
		$second = Tid::forRecord($time, 'post-1|did:plc:alice');

		self::assertSame($first, $second);
		self::assertTrue(Tid::isValid($first));
		self::assertSame(13, strlen($first));
	}

	public function testDifferentSeedsProduceDifferentKeys(): void
	{
		$time = Time::parse('2026-11-11T10:00:00Z');

		self::assertNotSame(
			Tid::forRecord($time, 'post-1|did:plc:alice'),
			Tid::forRecord($time, 'post-2|did:plc:alice'),
		);
	}

	public function testItRejectsAnEmptySeed(): void
	{
		$this->expectException(InvalidArgumentException::class);

		Tid::forRecord(Time::parse('2026-11-11T10:00:00Z'), '');
	}

	public function testTheValidPatternRejectsNonTidValues(): void
	{
		self::assertFalse(Tid::isValid('1'.str_repeat('a', 12)));
		self::assertFalse(Tid::isValid('234567abcdefg1'));
		self::assertFalse(Tid::isValid('short'));
	}
}
