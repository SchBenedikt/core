<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Domain\Filter\FilterRule;
use AlohaSocial\Core\Domain\Filter\FilterRuleRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The owner and ordering rules both filter repositories must share.
 */
abstract class FilterRuleRepositoryTestCase extends TestCase
{
	abstract protected function repository(): FilterRuleRepository;

	public function testARuleRoundTripsWithContextsAndKeywords(): void
	{
		$repository = $this->repository();
		$repository->insert($this->rule());

		$found = $repository->find('filter-1');

		self::assertNotNull($found);
		self::assertSame('alice', $found->userId);
		self::assertSame('Spoilers', $found->name);
		self::assertSame(FilterAction::Hide, $found->action);
		self::assertSame([FilterContext::Home, FilterContext::Public], $found->contexts);
		self::assertCount(2, $found->keywords);
		self::assertSame('spoiler', $found->keywords[0]->text);
		self::assertTrue($found->keywords[0]->wholeWord);
		self::assertSame('2026-12-01T00:00:00Z', Time::format($found->expiresAt));
	}

	public function testAnIdCannotBeStoredTwice(): void
	{
		$repository = $this->repository();
		$repository->insert($this->rule());

		$this->expectException(DatabaseException::class);

		$repository->insert($this->rule());
	}

	public function testAnUpdateReplacesKeywordsAndKeepsTheOwner(): void
	{
		$repository = $this->repository();
		$repository->insert($this->rule());

		$updated = new FilterRule(
			id: 'filter-1',
			userId: 'alice',
			name: 'Renamed',
			action: FilterAction::Warn,
			contexts: [FilterContext::Notifications],
			keywords: [new FilterKeyword('renamed')],
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
			expiresAt: null,
		);
		$repository->update($updated);

		$found = $repository->find('filter-1');
		self::assertNotNull($found);
		self::assertSame('Renamed', $found->name);
		self::assertSame(FilterAction::Warn, $found->action);
		self::assertSame([FilterContext::Notifications], $found->contexts);
		self::assertCount(1, $found->keywords);
		self::assertNull($found->expiresAt);
	}

	public function testDeleteIsOwnerScoped(): void
	{
		$repository = $this->repository();
		$repository->insert($this->rule());

		self::assertFalse($repository->delete('filter-1', 'bob'));
		self::assertNotNull($repository->find('filter-1'));

		self::assertTrue($repository->delete('filter-1', 'alice'));
		self::assertNull($repository->find('filter-1'));
	}

	public function testForUserIsNewestFirstAndCountsOnlyTheOwner(): void
	{
		$repository = $this->repository();
		$repository->insert($this->rule());
		$repository->insert($this->rule(
			'filter-2',
			'alice',
			Time::parse('2026-11-11T11:00:00Z'),
		));
		$repository->insert($this->rule(
			'filter-bob',
			'bob',
			Time::parse('2026-11-11T12:00:00Z'),
		));

		self::assertSame(
			['filter-2', 'filter-1'],
			array_map(static fn (FilterRule $rule): string => $rule->id, $repository->forUser('alice')),
		);
		self::assertSame(2, $repository->countForUser('alice'));
		self::assertSame(1, $repository->countForUser('bob'));
	}

	private function rule(
		string $id = 'filter-1',
		string $userId = 'alice',
		?\DateTimeImmutable $createdAt = null,
	): FilterRule {
		return new FilterRule(
			id: $id,
			userId: $userId,
			name: 'Spoilers',
			action: FilterAction::Hide,
			contexts: [FilterContext::Home, FilterContext::Public],
			keywords: [
				new FilterKeyword('spoiler', true),
				new FilterKeyword('twist'),
			],
			createdAt: $createdAt ?? Time::parse('2026-11-11T10:00:00Z'),
			expiresAt: Time::parse('2026-12-01T00:00:00Z'),
		);
	}
}
