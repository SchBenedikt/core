<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\PollVote;
use AlohaSocial\Core\Support\Time;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PollVoteTest extends TestCase
{
	public function testPositionsAreSortedAndNormalizedToSecondPrecision(): void
	{
		$vote = new PollVote(
			postId: 'p1',
			userId: 'alice',
			positions: [2, 0],
			createdAt: new \DateTimeImmutable('2026-11-11T10:00:00.789Z'),
		);

		self::assertSame([0, 2], $vote->positions);
		self::assertSame('2026-11-11T10:00:00Z', Time::format($vote->createdAt));
	}

	/**
	 * @param list<int> $positions
	 */
	#[DataProvider('invalidPositions')]
	public function testInvalidPositionsAreRejected(array $positions): void
	{
		$this->expectException(InvalidArgumentException::class);

		new PollVote(
			postId: 'p1',
			userId: 'alice',
			positions: $positions,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		);
	}

	/**
	 * @return iterable<string, array{list<int>}>
	 */
	public static function invalidPositions(): iterable
	{
		yield 'empty' => [[]];
		yield 'negative' => [[-1]];
		yield 'too high' => [[4]];
		yield 'duplicate' => [[0, 0]];
		yield 'not a list' => [[1 => 0]];
	}
}
