<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PollTest extends TestCase
{
	public function testSerializesTheMastodonRelevantFields(): void
	{
		$poll = new Poll(
			options: [new PollOption(' Yes '), new PollOption('No')],
			multiple: true,
			hideTotals: false,
			expiresAt: Time::parse('2026-11-11T11:00:00Z'),
		);

		self::assertSame([
			'options' => [
				['title' => 'Yes', 'votes_count' => 0],
				['title' => 'No', 'votes_count' => 0],
			],
			'multiple' => true,
			'hide_totals' => false,
			'expires_at' => '2026-11-11T11:00:00Z',
			'votes_count' => 0,
			'voters_count' => 0,
		], $poll->toArray());
	}

	public function testExpiryIsInclusiveAtTheBoundary(): void
	{
		$poll = new Poll(
			[new PollOption('Yes'), new PollOption('No')],
			false,
			false,
			Time::parse('2026-11-11T11:00:00Z'),
		);

		self::assertFalse($poll->isExpired(Time::parse('2026-11-11T10:59:59Z')));
		self::assertTrue($poll->isExpired(Time::parse('2026-11-11T11:00:00Z')));
	}

	public function testRejectsFewerThanTwoOptions(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Poll(
			[new PollOption('Only')],
			false,
			false,
			Time::parse('2026-11-11T11:00:00Z'),
		);
	}

	public function testRejectsMoreThanFourOptions(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Poll(
			[
				new PollOption('1'),
				new PollOption('2'),
				new PollOption('3'),
				new PollOption('4'),
				new PollOption('5'),
			],
			false,
			false,
			Time::parse('2026-11-11T11:00:00Z'),
		);
	}

	public function testOptionRejectsAnEmptyOrTooLongTitle(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new PollOption('   ');
	}

	public function testOptionRejectsATooLongTitle(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new PollOption(str_repeat('x', PollOption::MAX_TITLE_LENGTH + 1));
	}
}
