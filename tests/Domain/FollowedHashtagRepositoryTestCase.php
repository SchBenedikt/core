<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Timeline\FollowedHashtag;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagRepository;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both followed-hashtag repositories must satisfy.
 *
 * Follows are private and idempotent. Pages are newest-first with the tag as
 * the tie-break, and both cursor parts are exclusive.
 */
abstract class FollowedHashtagRepositoryTestCase extends TestCase
{
	abstract protected function repository(): FollowedHashtagRepository;

	public function testFollowIsIdempotentAndScopedToTheAccount(): void
	{
		$repository = $this->repository();

		self::assertTrue($repository->follow('alice', 'devops', Time::parse('2026-11-11T10:00:00Z')));
		self::assertFalse($repository->follow('alice', 'devops', Time::parse('2026-11-11T11:00:00Z')));
		self::assertSame(
			'2026-11-11T10:00:00Z',
			Time::format($repository->find('alice', 'devops')?->createdAt ?? Time::parse('2026-11-11T00:00:00Z')),
		);
		self::assertNull($repository->find('bob', 'devops'));
		self::assertSame(1, $repository->count('alice'));
		self::assertSame(0, $repository->count('bob'));

		self::assertTrue($repository->follow('bob', 'devops', Time::parse('2026-11-11T10:00:00Z')));
		self::assertSame(1, $repository->count('bob'));
	}

	public function testPageIsNewestFirstAndBothCursorPartsAreExclusive(): void
	{
		$repository = $this->repository();
		$repository->follow('alice', 'aaa', Time::parse('2026-11-11T10:00:00Z'));
		$repository->follow('alice', 'bbb', Time::parse('2026-11-11T10:00:00Z'));
		$repository->follow('alice', 'ccc', Time::parse('2026-11-11T10:02:00Z'));
		$repository->follow('bob', 'other', Time::parse('2026-11-11T10:01:00Z'));

		self::assertSame(
			['ccc', 'bbb', 'aaa'],
			array_map(static fn (FollowedHashtag $follow): string => $follow->tag, $repository->page('alice', 10)),
		);
		self::assertSame(
			['ccc', 'bbb'],
			array_map(
				static fn (FollowedHashtag $follow): string => $follow->tag,
				$repository->page('alice', 2),
			),
		);
		self::assertSame(
			['bbb', 'aaa'],
			array_map(
				static fn (FollowedHashtag $follow): string => $follow->tag,
				$repository->page('alice', 10, Time::parse('2026-11-11T10:02:00Z')),
			),
		);
		self::assertSame(
			['aaa'],
			array_map(
				static fn (FollowedHashtag $follow): string => $follow->tag,
				$repository->page('alice', 10, Time::parse('2026-11-11T10:00:00Z'), 'bbb'),
			),
		);
		self::assertSame(['ccc', 'bbb', 'aaa'], $repository->tags('alice'));
		self::assertSame(['other'], $repository->tags('bob'));
	}

	public function testUnfollowIsIdempotentAndOwnerScoped(): void
	{
		$repository = $this->repository();
		$repository->follow('alice', 'devops', Time::parse('2026-11-11T10:00:00Z'));

		self::assertFalse($repository->unfollow('bob', 'devops'));
		self::assertNotNull($repository->find('alice', 'devops'));

		self::assertTrue($repository->unfollow('alice', 'devops'));
		self::assertNull($repository->find('alice', 'devops'));
		self::assertFalse($repository->unfollow('alice', 'devops'));
		self::assertSame(0, $repository->count('alice'));
	}
}
