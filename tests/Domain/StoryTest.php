<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StoryTest extends TestCase
{
	public function testAStoryNeedsExactlyOneMediaReference(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Story(
			's1',
			'alice',
			true,
			'https://social.example/stories/s1',
			'',
			Time::parse('2026-11-11T10:00:00Z'),
			Time::parse('2026-11-12T10:00:00Z'),
		);
	}

	public function testAStoryMustExpireAfterPublication(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Story(
			's1',
			'alice',
			true,
			'https://social.example/stories/s1',
			'',
			Time::parse('2026-11-11T10:00:00Z'),
			Time::parse('2026-11-11T10:00:00Z'),
			'a1',
		);
	}

	public function testExpiryIsInclusiveAtTheBoundary(): void
	{
		$story = new Story(
			's1',
			'alice',
			true,
			'https://social.example/stories/s1',
			'',
			Time::parse('2026-11-11T10:00:00Z'),
			Time::parse('2026-11-12T10:00:00Z'),
			'a1',
		);

		self::assertFalse($story->isExpiredAt(Time::parse('2026-11-12T09:59:59Z')));
		self::assertTrue($story->isExpiredAt(Time::parse('2026-11-12T10:00:00Z')));
	}
}
