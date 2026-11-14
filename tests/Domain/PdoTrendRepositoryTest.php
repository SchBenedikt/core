<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Search\PdoSearchIndex;
use AlohaSocial\Core\Domain\Search\PdoTrendRepository;
use AlohaSocial\Core\Domain\Search\SearchIndexSchema;
use AlohaSocial\Core\Domain\Search\SearchKind;
use AlohaSocial\Core\Domain\Search\SearchRecord;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Domain\Search\TrendSchema;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoTrendRepositoryTest extends TestCase
{
	public function testPdoCountsFollowIndexedPublicRows(): void
	{
		$db = new PdoConnection(new PDO('sqlite::memory:'));
		SearchIndexSchema::create($db);
		TrendSchema::create($db);
		$index = new PdoSearchIndex($db);
		$trends = new PdoTrendRepository($db);

		$first = $this->record('post:1', ['php', 'social']);
		$second = $this->record('post:2', ['php']);
		$index->upsert($first);
		$index->upsert($second);
		$trends->record($first);
		$trends->record($second);

		self::assertSame([
			['hashtag' => 'php', 'count' => 2],
			['hashtag' => 'social', 'count' => 1],
		], array_map(static fn ($trend): array => $trend->toArray(), $trends->top(
			Time::parse('2026-11-14T00:00:00Z'),
			10,
		)));

		$trends->record($this->record('post:2', ['php']));
		self::assertSame(2, $trends->top(Time::parse('2026-11-14T00:00:00Z'), 10, 'php')[0]->count);

		$trends->remove('post:1');
		self::assertSame(1, $trends->top(Time::parse('2026-11-14T00:00:00Z'), 10, 'php')[0]->count);
	}

	/**
	 * @param list<string> $hashtags
	 */
	private function record(string $id, array $hashtags): SearchRecord
	{
		return SearchRecord::create(
			id: $id,
			kind: SearchKind::Post,
			source: SearchSource::Local,
			content: 'post '.$id,
			publishedAt: Time::parse('2026-11-14T10:00:00Z'),
			cachedAt: Time::parse('2026-11-14T10:00:00Z'),
			authorId: 'alice',
			hashtags: $hashtags,
		);
	}
}
