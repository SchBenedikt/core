<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Search\InMemoryTrendRepository;
use AlohaSocial\Core\Domain\Search\SearchKind;
use AlohaSocial\Core\Domain\Search\SearchRecord;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryTrendRepositoryTest extends TestCase
{
	public function testCountsAreWindowedRankedAndReplaceOnReindex(): void
	{
		$trends = new InMemoryTrendRepository();
		$trends->record($this->record('post:1', ['php', 'social'], '2026-11-14T10:00:00Z'));
		$trends->record($this->record('post:2', ['php'], '2026-11-14T10:01:00Z'));
		$trends->record($this->record('post:old', ['php'], '2026-11-01T10:00:00Z'));

		$page = $trends->top(Time::parse('2026-11-14T00:00:00Z'), 10);
		self::assertSame([
			['hashtag' => 'php', 'count' => 2],
			['hashtag' => 'social', 'count' => 1],
		], array_map(static fn ($trend): array => $trend->toArray(), $page));

		$trends->record($this->record('post:2', [], '2026-11-14T10:01:00Z'));
		self::assertSame(
			[['hashtag' => 'php', 'count' => 1], ['hashtag' => 'social', 'count' => 1]],
			array_map(static fn ($trend): array => $trend->toArray(), $trends->top(
				Time::parse('2026-11-14T00:00:00Z'),
				10,
			)),
		);
		self::assertSame(
			[['hashtag' => 'php', 'count' => 1]],
			array_map(static fn ($trend): array => $trend->toArray(), $trends->top(
				Time::parse('2026-11-14T00:00:00Z'),
				10,
				'PH',
			)),
		);

		$trends->remove('post:1');
		self::assertSame(
			[],
			array_map(static fn ($trend): array => $trend->toArray(), $trends->top(
				Time::parse('2026-11-14T00:00:00Z'),
				10,
			)),
		);
	}

	/**
	 * @param list<string> $hashtags
	 */
	private function record(string $id, array $hashtags, string $publishedAt): SearchRecord
	{
		return SearchRecord::create(
			id: $id,
			kind: SearchKind::Post,
			source: SearchSource::Local,
			content: 'post '.$id,
			publishedAt: new DateTimeImmutable($publishedAt),
			cachedAt: new DateTimeImmutable($publishedAt),
			authorId: 'alice',
			hashtags: $hashtags,
		);
	}
}
