<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Search\InvalidSearchRecordException;
use AlohaSocial\Core\Domain\Search\SearchIndex;
use AlohaSocial\Core\Domain\Search\SearchKind;
use AlohaSocial\Core\Domain\Search\SearchRecord;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both search-index adapters must satisfy.
 */
abstract class SearchIndexRepositoryTestCase extends TestCase
{
	abstract protected function index(): SearchIndex;

	public function testUpsertFindAndRemoveAreIdempotent(): void
	{
		$index = $this->index();
		$record = $this->record('post:1', 'hello world', 'alice', '2026-11-14T10:00:00Z');

		$index->upsert($record);
		$index->upsert($record);

		self::assertSame('hello world', $index->find('post:1')?->content);
		self::assertSame(['local'], [$index->find('post:1')?->source->value]);

		$index->remove('post:1');
		self::assertNull($index->find('post:1'));
		$index->remove('post:1');
	}

	public function testSearchMatchesContentAndAliasesAndFiltersKinds(): void
	{
		$index = $this->index();
		$index->upsert($this->record('post:1', 'hello world', 'alice', '2026-11-14T10:00:00Z'));
		$index->upsert(SearchRecord::create(
			'actor:https://remote.example/users/bob',
			SearchKind::Account,
			SearchSource::Remote,
			'@bob@remote.example Bob',
			Time::parse('2026-11-14T10:01:00Z'),
			Time::parse('2026-11-14T10:02:00Z'),
			'https://remote.example/users/bob',
			aliases: ['bob@remote.example', '@bob@remote.example'],
		));

		$posts = $index->search('hello', [SearchKind::Post], 10);
		self::assertCount(1, $posts->records);
		self::assertSame('post:1', $posts->records[0]->id);

		$accounts = $index->search('@bob@remote.example', [SearchKind::Account], 10);
		self::assertCount(1, $accounts->records);
		self::assertSame('actor:https://remote.example/users/bob', $accounts->records[0]->id);

		self::assertSame([], $index->search('hello', [SearchKind::Account], 10)->records);
		self::assertSame([], $index->search('', [SearchKind::Post], 10)->records);
	}

	public function testRankThenTimeThenIdMakeAFixedCursorSafe(): void
	{
		$index = $this->index();
		$index->upsert($this->record('post:exact', 'hello', 'alice', '2026-11-14T10:00:00Z'));
		$index->upsert($this->record('post:prefix', 'hello world', 'alice', '2026-11-14T10:00:00Z'));
		$index->upsert($this->record('post:substring', 'say hello', 'alice', '2026-11-14T10:00:00Z'));

		$first = $index->search('hello', [SearchKind::Post], 2);
		self::assertSame(['post:exact', 'post:prefix'], array_map(
			static fn (SearchRecord $record): string => $record->id,
			$first->records,
		));
		self::assertNotNull($first->nextCursor);

		$second = $index->search('hello', [SearchKind::Post], 2, $first->nextCursor);
		self::assertSame(['post:substring'], array_map(
			static fn (SearchRecord $record): string => $record->id,
			$second->records,
		));
		self::assertNull($second->nextCursor);
	}

	public function testExcludedAuthorsAndPrivateObjectsAreNeverReturned(): void
	{
		$index = $this->index();
		$index->upsert($this->record('post:alice', 'hello alice', 'alice', '2026-11-14T10:00:00Z'));
		$index->upsert($this->record('post:bob', 'hello bob', 'bob', '2026-11-14T10:01:00Z'));

		$page = $index->search('hello', [SearchKind::Post], 10, null, ['bob']);
		self::assertSame(['post:alice'], array_map(
			static fn (SearchRecord $record): string => $record->id,
			$page->records,
		));

		$this->expectException(InvalidSearchRecordException::class);
		$this->record('post:private', 'hello private', 'alice', '2026-11-14T10:02:00Z', Visibility::Followers);
	}

	private function record(
		string $id,
		string $content,
		string $authorId,
		string $publishedAt,
		Visibility $visibility = Visibility::Public,
	): SearchRecord {
		return new SearchRecord(
			id: $id,
			kind: SearchKind::Post,
			source: SearchSource::Local,
			content: $content,
			publishedAt: Time::parse($publishedAt),
			cachedAt: Time::parse($publishedAt),
			visibility: $visibility,
			authorId: $authorId,
		);
	}
}
