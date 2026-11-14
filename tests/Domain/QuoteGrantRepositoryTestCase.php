<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\QuoteGrant;
use AlohaSocial\Core\Domain\Post\QuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\QuoteState;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

abstract class QuoteGrantRepositoryTestCase extends TestCase
{
	abstract protected function repository(): QuoteGrantRepository;

	public function testAGrantRoundTripsWithItsObjectsAndActors(): void
	{
		$repository = $this->repository();
		$repository->insert($this->grant());

		$found = $repository->find('grant-1');
		self::assertNotNull($found);
		self::assertSame('https://social.example/api/v1/posts/quote-1', $found->interactingObjectId);
		self::assertSame('https://social.example/api/v1/posts/target-1', $found->quotedObjectId);
		self::assertSame('https://social.example/@bob', $found->actorId);
		self::assertSame('https://social.example/@alice', $found->quotedActorId);
		self::assertSame(QuoteState::Accepted, $found->state);
		self::assertTrue($found->isActive());
	}

	public function testOneObjectCannotCarryTwoGrants(): void
	{
		$repository = $this->repository();
		$repository->insert($this->grant());

		$this->expectException(DatabaseException::class);

		$repository->insert(new QuoteGrant(
			id: 'grant-2',
			quotedObjectId: 'https://social.example/api/v1/posts/target-1',
			interactingObjectId: 'https://social.example/api/v1/posts/quote-1',
			actorId: 'https://social.example/@bob',
			quotedActorId: 'https://social.example/@alice',
			state: QuoteState::Accepted,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
			updatedAt: Time::parse('2026-11-11T10:00:00Z'),
		));
	}

	public function testRevokingIsFinalAndIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->grant());

		self::assertTrue($repository->close('grant-1', QuoteState::Revoked, Time::parse('2026-11-11T10:05:00Z')));
		self::assertFalse($repository->close('grant-1', QuoteState::Revoked, Time::parse('2026-11-11T10:06:00Z')));
		self::assertFalse($repository->close('grant-1', QuoteState::Accepted, Time::parse('2026-11-11T10:07:00Z')));

		$found = $repository->find('grant-1');
		self::assertSame(QuoteState::Revoked, $found?->state);
		self::assertFalse($found?->isActive());
		self::assertNull($repository->find('missing'));
	}

	private function grant(): QuoteGrant
	{
		return new QuoteGrant(
			id: 'grant-1',
			quotedObjectId: 'https://social.example/api/v1/posts/target-1',
			interactingObjectId: 'https://social.example/api/v1/posts/quote-1',
			actorId: 'https://social.example/@bob',
			quotedActorId: 'https://social.example/@alice',
			state: QuoteState::Accepted,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
			updatedAt: Time::parse('2026-11-11T10:00:00Z'),
		);
	}
}
