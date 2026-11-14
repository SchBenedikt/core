<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Search\InMemorySearchIndex;
use AlohaSocial\Core\Domain\Search\InMemoryTrendRepository;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\Search\SearchIndexer;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class SearchIndexerTest extends TestCase
{
	private InMemorySearchIndex $index;

	private InMemoryTrendRepository $trends;

	private SearchIndexer $indexer;

	protected function setUp(): void
	{
		$this->index = new InMemorySearchIndex();
		$this->trends = new InMemoryTrendRepository();
		$this->indexer = new SearchIndexer(
			$this->index,
			$this->trends,
			new FrozenClock(Time::parse('2026-11-14T10:00:00Z')),
		);
	}

	public function testPublicPostsAreIndexedAndContributeTrends(): void
	{
		$post = $this->post('post-1', 'hello #php #social', Visibility::Public);

		$this->indexer->indexPost($post);

		$record = $this->index->find('post:post-1');
		self::assertNotNull($record);
		self::assertSame(SearchSource::Local, $record->source);
		self::assertSame(['php', 'social'], $record->hashtags);
		self::assertSame([
			['hashtag' => 'php', 'count' => 1],
			['hashtag' => 'social', 'count' => 1],
		], array_map(static fn ($trend): array => $trend->toArray(), $this->trends->top(
			Time::parse('2026-11-14T00:00:00Z'),
			10,
		)));
	}

	public function testPrivateAndDeletedPostsAreRemovedFromDiscovery(): void
	{
		$this->indexer->indexPost($this->post('post-1', 'hello #php', Visibility::Public));
		self::assertNotNull($this->index->find('post:post-1'));

		$this->indexer->indexPost($this->post('post-1', 'hello #php', Visibility::Followers));
		self::assertNull($this->index->find('post:post-1'));
		self::assertSame([], $this->trends->top(Time::parse('2026-11-14T00:00:00Z'), 10));

		$this->indexer->indexPost($this->post('post-2', 'hello #php', Visibility::Public));
		$this->indexer->indexPost($this->post('post-2', 'hello #php', Visibility::Public, true));
		self::assertNull($this->index->find('post:post-2'));
	}

	private function post(
		string $id,
		string $content,
		Visibility $visibility,
		bool $deleted = false,
	): Post {
		return new Post(
			id: $id,
			authorId: 'alice',
			content: $content,
			visibility: $visibility,
			publishedAt: Time::parse('2026-11-14T10:00:00Z'),
			deletedAt: $deleted ? Time::parse('2026-11-14T10:01:00Z') : null,
		);
	}
}
