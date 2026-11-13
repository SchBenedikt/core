<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Timeline\AccountListNotFoundException;
use AlohaSocial\Core\Domain\Timeline\InMemoryAccountListRepository;
use AlohaSocial\Core\Domain\Timeline\ListRepliesPolicy;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\AccountListService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

final class AccountListServiceTest extends TestCase
{
	private FrozenClock $clock;

	private AccountListService $lists;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-13T12:00:00Z'));
		$this->lists = new AccountListService(
			new InMemoryAccountListRepository(),
			$this->clock,
			new SequenceIdGenerator(),
		);
	}

	public function testCreateUsesDefaultsAndTrimsTheTitle(): void
	{
		$list = $this->lists->create('alice', '  Friends  ');

		self::assertSame('list-id-1', $list->id);
		self::assertSame('Friends', $list->title);
		self::assertSame(ListRepliesPolicy::Followed, $list->repliesPolicy);
		self::assertFalse($list->exclusive);
		self::assertSame('2026-11-13T12:00:00Z', $list->createdAt->format('Y-m-d\TH:i:s\Z'));
		self::assertSame($list->createdAt, $list->updatedAt);
		self::assertSame([$list], $this->lists->lists('alice'));
	}

	public function testCreateAcceptsAPolicyAndTheExclusiveFlag(): void
	{
		$list = $this->lists->create('alice', 'Quiet', 'none', true);

		self::assertSame(ListRepliesPolicy::None, $list->repliesPolicy);
		self::assertTrue($list->exclusive);
		self::assertSame($list->id, $this->lists->find('alice', $list->id)->id);
	}

	public function testUpdateIsPartialAndScopedToTheOwner(): void
	{
		$list = $this->lists->create('alice', 'Friends');
		$this->clock->advance(5);
		$updated = $this->lists->update(
			'alice',
			$list->id,
			'Close friends',
			'list',
			true,
		);

		self::assertSame('Close friends', $updated->title);
		self::assertSame(ListRepliesPolicy::Members, $updated->repliesPolicy);
		self::assertTrue($updated->exclusive);
		self::assertSame($list->createdAt, $updated->createdAt);
		self::assertSame('2026-11-13T12:00:05Z', $updated->updatedAt->format('Y-m-d\TH:i:s\Z'));

		self::assertSame(
			$updated,
			$this->lists->update('alice', $list->id),
		);

		$this->expectException(AccountListNotFoundException::class);
		$this->lists->update('bob', $list->id, 'Stolen');
	}

	public function testAnEmptyOrInvalidTitleAndPolicyAreRejected(): void
	{
		foreach ([null, '', '   '] as $title) {
			try {
				$this->lists->create('alice', $title);
				self::fail('Expected the title to be rejected.');
			} catch (ValidationException $exception) {
				self::assertArrayHasKey('title', $exception->fields());
			}
		}

		$this->expectException(ValidationException::class);
		$this->lists->create('alice', 'Friends', 'everyone');
	}

	public function testMembershipIsDeduplicatedAndIdempotent(): void
	{
		$list = $this->lists->create('alice', 'Friends');
		$this->lists->addMembers('alice', $list->id, ['bob', 'bob', 'carol']);

		self::assertSame(['carol', 'bob'], $this->lists->memberIds('alice', $list->id));

		$this->clock->advance(1);
		$this->lists->addMembers('alice', $list->id, ['bob']);
		self::assertSame(['carol', 'bob'], $this->lists->memberIds('alice', $list->id));

		$this->lists->removeMembers('alice', $list->id, ['bob', 'mallory']);
		self::assertSame(['carol'], $this->lists->memberIds('alice', $list->id));
	}

	public function testMembershipCommandsRejectEmptyOrOversizedInput(): void
	{
		$list = $this->lists->create('alice', 'Friends');

		try {
			$this->lists->addMembers('alice', $list->id, []);
			self::fail('Expected an empty membership list to be rejected.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('account_ids', $exception->fields());
		}

		$ids = [];
		for ($index = 0; $index <= AccountListService::MAX_ACCOUNTS_PER_REQUEST; $index++) {
			$ids[] = 'account-' . $index;
		}

		$this->expectException(ValidationException::class);
		$this->lists->addMembers('alice', $list->id, $ids);
	}

	public function testDuplicateMembershipIdsStillCountTowardsTheRequestLimit(): void
	{
		$list = $this->lists->create('alice', 'Friends');

		$this->expectException(ValidationException::class);
		$this->lists->addMembers(
			'alice',
			$list->id,
			array_fill(0, AccountListService::MAX_ACCOUNTS_PER_REQUEST + 1, 'bob'),
		);
	}

	public function testForeignMembershipReadsAndDeletesAreNotFound(): void
	{
		$list = $this->lists->create('alice', 'Friends');
		$this->lists->addMembers('alice', $list->id, ['bob']);

		$this->expectException(AccountListNotFoundException::class);
		$this->lists->memberIds('bob', $list->id);
	}

	public function testExclusiveActorIdsComeOnlyFromExclusiveLists(): void
	{
		$normal = $this->lists->create('alice', 'Normal');
		$exclusive = $this->lists->create('alice', 'Exclusive', 'followed', true);
		$this->lists->addMembers('alice', $normal->id, ['bob']);
		$this->lists->addMembers('alice', $exclusive->id, ['carol']);

		self::assertSame(['carol'], $this->lists->exclusiveActorIds('alice'));
	}

	public function testDeleteIsScopedAndRemovesTheList(): void
	{
		$list = $this->lists->create('alice', 'Friends');

		try {
			$this->lists->delete('bob', $list->id);
			self::fail('Expected a foreign delete to be rejected.');
		} catch (AccountListNotFoundException) {
			self::assertSame($list->id, $this->lists->find('alice', $list->id)->id);
		}

		$this->lists->delete('alice', $list->id);

		$this->expectException(AccountListNotFoundException::class);
		$this->lists->find('alice', $list->id);
	}
}
