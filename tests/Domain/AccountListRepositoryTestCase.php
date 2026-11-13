<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Timeline\AccountList;
use AlohaSocial\Core\Domain\Timeline\AccountListRepository;
use AlohaSocial\Core\Domain\Timeline\ListRepliesPolicy;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both account-list repositories must satisfy.
 *
 * Lists are private, membership is idempotent, and every read is scoped to
 * the owner. The list and member order is newest-first with an id tie-break.
 */
abstract class AccountListRepositoryTestCase extends TestCase
{
	abstract protected function repository(): AccountListRepository;

	public function testListsFindAndOrderAreScopedToTheOwner(): void
	{
		$repository = $this->repository();
		$repository->insert($this->list('old', 'alice', 'Old', '2026-11-11T10:00:00Z'));
		$repository->insert($this->list('new', 'alice', 'New', '2026-11-11T10:02:00Z'));
		$repository->insert($this->list('bob', 'bob', 'Bob', '2026-11-11T10:01:00Z'));

		self::assertSame('Old', $repository->find('alice', 'old')?->title);
		self::assertNull($repository->find('bob', 'old'));
		self::assertSame(
			['new', 'old'],
			array_map(static fn (AccountList $list): string => $list->id, $repository->lists('alice')),
		);
		self::assertCount(1, $repository->lists('bob'));
	}

	public function testADuplicateIdIsRejected(): void
	{
		$repository = $this->repository();
		$repository->insert($this->list('one', 'alice', 'One'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->list('one', 'bob', 'Other owner'));
	}

	public function testUpdateAndDeleteAreScopedToTheOwnerAndDeleteMembership(): void
	{
		$repository = $this->repository();
		$repository->insert($this->list('one', 'alice', 'One'));
		$repository->addMembers('alice', 'one', ['bob'], Time::parse('2026-11-11T10:01:00Z'));

		$updated = $this->list(
			'one',
			'alice',
			'Renamed',
			'2026-11-11T10:00:00Z',
			ListRepliesPolicy::Members,
			true,
			'2026-11-11T10:05:00Z',
		);

		self::assertFalse($repository->update($this->list(
			'one',
			'bob',
			'Foreign',
		)));
		self::assertTrue($repository->update($updated));
		self::assertSame('Renamed', $repository->find('alice', 'one')?->title);
		self::assertSame(ListRepliesPolicy::Members, $repository->find('alice', 'one')?->repliesPolicy);
		self::assertTrue($repository->find('alice', 'one')?->exclusive);

		self::assertFalse($repository->delete('bob', 'one'));
		self::assertNotNull($repository->find('alice', 'one'));
		self::assertSame(['bob'], $repository->memberIds('alice', 'one'));

		self::assertTrue($repository->delete('alice', 'one'));
		self::assertNull($repository->find('alice', 'one'));
		self::assertSame([], $repository->memberIds('alice', 'one'));
	}

	public function testMembershipIsIdempotentOrderedAndOwnerScoped(): void
	{
		$repository = $this->repository();
		$repository->insert($this->list('one', 'alice', 'One'));
		$repository->insert($this->list('other', 'bob', 'Other'));

		self::assertSame(2, $repository->addMembers(
			'alice',
			'one',
			['bob', 'carol'],
			Time::parse('2026-11-11T10:00:00Z'),
		));
		self::assertSame(0, $repository->addMembers(
			'alice',
			'one',
			['carol'],
			Time::parse('2026-11-11T10:01:00Z'),
		));
		self::assertSame(['carol', 'bob'], $repository->memberIds('alice', 'one'));
		self::assertSame([], $repository->memberIds('bob', 'one'));
		self::assertSame(0, $repository->addMembers(
			'bob',
			'one',
			['mallory'],
			Time::parse('2026-11-11T10:02:00Z'),
		));

		self::assertSame(0, $repository->removeMembers('bob', 'one', ['bob']));
		self::assertSame(1, $repository->removeMembers('alice', 'one', ['bob']));
		self::assertSame(['carol'], $repository->memberIds('alice', 'one'));
	}

	public function testExclusiveActorIdsOnlyIncludeOwnedExclusiveLists(): void
	{
		$repository = $this->repository();
		$repository->insert($this->list('exclusive', 'alice', 'Exclusive', exclusive: true));
		$repository->insert($this->list('normal', 'alice', 'Normal'));
		$repository->insert($this->list('bob', 'bob', 'Bob', exclusive: true));

		$repository->addMembers('alice', 'exclusive', ['bob'], Time::parse('2026-11-11T10:00:00Z'));
		$repository->addMembers('alice', 'normal', ['carol'], Time::parse('2026-11-11T10:00:00Z'));
		$repository->addMembers('bob', 'bob', ['dave'], Time::parse('2026-11-11T10:00:00Z'));

		self::assertSame(['bob'], $repository->exclusiveActorIds('alice'));
		self::assertSame(['dave'], $repository->exclusiveActorIds('bob'));
	}

	private function list(
		string $id,
		string $userId,
		string $title,
		string $createdAt = '2026-11-11T10:00:00Z',
		ListRepliesPolicy $policy = ListRepliesPolicy::Followed,
		bool $exclusive = false,
		?string $updatedAt = null,
	): AccountList {
		return new AccountList(
			id: $id,
			userId: $userId,
			title: $title,
			repliesPolicy: $policy,
			exclusive: $exclusive,
			createdAt: Time::parse($createdAt),
			updatedAt: Time::parse($updatedAt ?? $createdAt),
		);
	}
}
