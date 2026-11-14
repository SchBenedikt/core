<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterKeyword;
use AlohaSocial\Core\Domain\Filter\FilterMatch;
use AlohaSocial\Core\Domain\Filter\FilterNotFoundException;
use AlohaSocial\Core\Domain\Filter\FilterRule;
use AlohaSocial\Core\Domain\Filter\InMemoryFilterRuleRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The matching and validation rules of the filter service.
 *
 * These cases pin the two behaviours a UI relies on: `hide` outranks `warn`
 * inside one context, and a `warn` result carries enough evidence to explain
 * the redaction without re-running the rule on the client.
 */
final class FilterServiceTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryFilterRuleRepository $repository;

	private FilterService $filters;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->repository = new InMemoryFilterRuleRepository();
		$this->filters = new FilterService($this->repository, $this->clock, new SequenceIdGenerator('filter-'));
	}

	public function testCreateListUpdateAndDeleteRoundTrip(): void
	{
		$created = $this->filters->create(
			'alice',
			'Spoilers',
			FilterAction::Hide,
			[FilterContext::Home],
			[new FilterKeyword('spoiler', true)],
			Time::parse('2026-12-01T00:00:00Z'),
		);

		self::assertSame('filter-1', $created->id);
		self::assertSame('alice', $created->userId);
		self::assertCount(1, $this->filters->forUser('alice'));

		$updated = $this->filters->update(
			'alice',
			$created->id,
			'Renamed',
			FilterAction::Warn,
			[FilterContext::Notifications],
			[new FilterKeyword('renamed')],
		);

		self::assertSame('Renamed', $updated->name);
		self::assertNull($updated->expiresAt);
		self::assertSame('2026-11-11T10:00:00Z', $updated->createdAt->format('Y-m-d\TH:i:s\Z'));

		$this->filters->delete('alice', $created->id);
		self::assertSame([], $this->filters->forUser('alice'));
	}

	public function testAnEmptyNameIsRefused(): void
	{
		$this->expectException(ValidationException::class);

		$this->filters->create('alice', '  ', FilterAction::Hide, [FilterContext::Home], [new FilterKeyword('x')]);
	}

	public function testARuleNeedsAContext(): void
	{
		try {
			$this->filters->create('alice', 'Name', FilterAction::Hide, [], [new FilterKeyword('x')]);
			self::fail('An empty context list must be refused.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('contexts', $exception->fields());
		}
	}

	public function testARepeatedContextIsRefused(): void
	{
		try {
			$this->filters->create(
				'alice',
				'Name',
				FilterAction::Hide,
				[FilterContext::Home, FilterContext::Home],
				[new FilterKeyword('x')],
			);
			self::fail('A repeated context must be refused.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('contexts', $exception->fields());
		}
	}

	public function testARepeatedKeywordIsRefusedCaseInsensitively(): void
	{
		try {
			$this->filters->create(
				'alice',
				'Name',
				FilterAction::Hide,
				[FilterContext::Home],
				[new FilterKeyword('Spoiler'), new FilterKeyword('spoiler')],
			);
			self::fail('A repeated keyword must be refused.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('keywords', $exception->fields());
		}
	}

	public function testAnExpiryInThePastIsRefused(): void
	{
		try {
			$this->filters->create(
				'alice',
				'Name',
				FilterAction::Hide,
				[FilterContext::Home],
				[new FilterKeyword('x')],
				Time::parse('2026-11-10T10:00:00Z'),
			);
			self::fail('A past expiry must be refused.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('expires_at', $exception->fields());
		}
	}

	public function testTheRuleLimitIsEnforced(): void
	{
		for ($index = 0; $index < FilterRule::MAX_RULES_PER_USER; $index++) {
			$this->filters->create(
				'alice',
				'Rule ' . $index,
				FilterAction::Hide,
				[FilterContext::Home],
				[new FilterKeyword('keyword-' . $index)],
			);
		}

		try {
			$this->filters->create('alice', 'One too many', FilterAction::Hide, [FilterContext::Home], [new FilterKeyword('x')]);
			self::fail('The per-user rule limit must be enforced.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('name', $exception->fields());
		}
	}

	public function testHideOutranksWarn(): void
	{
		$this->filters->create('alice', 'Warn', FilterAction::Warn, [FilterContext::Home], [new FilterKeyword('spoiler')]);
		$this->filters->create('alice', 'Hide', FilterAction::Hide, [FilterContext::Home], [new FilterKeyword('spoiler')]);

		$match = $this->filters->matchTexts(
			$this->filters->activeFor('alice', FilterContext::Home),
			FilterContext::Home,
			['A spoiler is here'],
		);

		self::assertNotNull($match);
		self::assertSame('Hide', $match->ruleName);
		self::assertSame(FilterAction::Hide, $match->action);
	}

	public function testWholeWordMatchingDoesNotMatchASubstring(): void
	{
		$this->filters->create('alice', 'Cats', FilterAction::Warn, [FilterContext::Home], [new FilterKeyword('cat', true)]);

		$rules = $this->filters->activeFor('alice', FilterContext::Home);
		self::assertNull($this->filters->matchTexts($rules, FilterContext::Home, ['concatenate everything']));
		self::assertNotNull($this->filters->matchTexts($rules, FilterContext::Home, ['my cat sleeps']));
	}

	public function testSubstringMatchingIsTheDefault(): void
	{
		$this->filters->create('alice', 'Cats', FilterAction::Warn, [FilterContext::Home], [new FilterKeyword('cat')]);

		self::assertNotNull($this->filters->matchTexts(
			$this->filters->activeFor('alice', FilterContext::Home),
			FilterContext::Home,
			['concatenate'],
		));
	}

	public function testMatchingIsUnicodeNormalizedAndCaseFolded(): void
	{
		$this->filters->create('alice', 'Cafe', FilterAction::Warn, [FilterContext::Home], [new FilterKeyword('CAFÉ')]);

		self::assertNotNull($this->filters->matchTexts(
			$this->filters->activeFor('alice', FilterContext::Home),
			FilterContext::Home,
			['Le café est prêt'],
		));
	}

	public function testAnExpiredRuleStopsMatching(): void
	{
		$this->filters->create(
			'alice',
			'Old',
			FilterAction::Hide,
			[FilterContext::Home],
			[new FilterKeyword('old')],
			Time::parse('2026-11-20T00:00:00Z'),
		);

		self::assertNotNull($this->filters->matchTexts(
			$this->filters->activeFor('alice', FilterContext::Home),
			FilterContext::Home,
			['old news'],
		));

		$this->clock->set(Time::parse('2026-11-21T00:00:00Z'));

		self::assertSame([], $this->filters->activeFor('alice', FilterContext::Home));
		self::assertNull($this->filters->matchTexts(
			$this->filters->activeFor('alice', FilterContext::Home),
			FilterContext::Home,
			['old news'],
		));
	}

	public function testAContextWithoutAMatchIsIgnored(): void
	{
		$this->filters->create('alice', 'Home only', FilterAction::Hide, [FilterContext::Home], [new FilterKeyword('secret')]);

		self::assertNotSame([], $this->filters->activeFor('alice', FilterContext::Home));
		self::assertSame([], $this->filters->activeFor('alice', FilterContext::Public));
	}

	public function testPostMatchingCoversContentWarningPollAndAltText(): void
	{
		$this->filters->create('alice', 'CW', FilterAction::Warn, [FilterContext::Thread], [new FilterKeyword('flashing')]);
		$this->filters->create('alice', 'Poll', FilterAction::Warn, [FilterContext::Thread], [new FilterKeyword('pineapple')]);
		$this->filters->create('alice', 'Alt', FilterAction::Warn, [FilterContext::Thread], [new FilterKeyword('sunset')]);

		$post = new Post(
			id: 'post-1',
			authorId: 'bob',
			content: 'Look',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			contentWarning: 'Flashing lights',
			poll: new Poll(
				options: [new PollOption('Pineapple'), new PollOption('No pineapple')],
				multiple: false,
				hideTotals: false,
				expiresAt: Time::parse('2026-11-12T09:00:00Z'),
			),
		);

		self::assertNotNull($this->filters->matchPost('alice', FilterContext::Thread, $post));

		$post = new Post(
			id: 'post-2',
			authorId: 'bob',
			content: 'Look',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
		);

		self::assertNull($this->filters->matchPost('alice', FilterContext::Thread, $post));
		self::assertNotNull($this->filters->matchPost('alice', FilterContext::Thread, $post, ['A sunset over the sea']));
	}

	public function testRedactionRemovesTextButKeepsMetadata(): void
	{
		$match = new FilterMatch('filter-1', 'Name', FilterAction::Warn, 'keyword', FilterContext::Home);

		$presented = $this->filters->redact([
			'id' => 'post-1',
			'content' => 'Secret text',
			'content_warning' => 'Spoiler',
			'poll' => ['options' => []],
			'attachments' => [['id' => 'media-1', 'alt_text' => 'A secret description']],
		]);

		self::assertSame('', $presented['content']);
		self::assertNull($presented['content_warning']);
		self::assertNull($presented['poll']);
		self::assertNull($presented['attachments'][0]['alt_text']);
		self::assertSame('media-1', $presented['attachments'][0]['id']);
		self::assertSame('post-1', $presented['id']);
		self::assertSame('filter-1', $this->filters->filterBlock($match)['filter_id']);
	}

	public function testAnotherReaderCannotReadOrDeleteTheRule(): void
	{
		$rule = $this->filters->create('alice', 'Mine', FilterAction::Hide, [FilterContext::Home], [new FilterKeyword('x')]);

		$this->expectException(FilterNotFoundException::class);

		$this->filters->delete('bob', $rule->id);
	}

	public function testAKeywordCannotExceedTheLimit(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new FilterKeyword(str_repeat('a', FilterKeyword::MAX_LENGTH + 1));
	}
}
