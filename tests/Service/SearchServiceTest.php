<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Search\InMemorySearchIndex;
use AlohaSocial\Core\Domain\Search\InMemoryTrendRepository;
use AlohaSocial\Core\Domain\Search\InvalidSearchCursorException;
use AlohaSocial\Core\Domain\Search\RemoteActorProfile;
use AlohaSocial\Core\Domain\Search\RemoteActorSearch;
use AlohaSocial\Core\Domain\Search\RemoteHandle;
use AlohaSocial\Core\Domain\Search\RemoteSearchException;
use AlohaSocial\Core\Domain\Search\SearchKind;
use AlohaSocial\Core\Domain\Search\SearchRecord;
use AlohaSocial\Core\Domain\Search\SearchSource;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Service\Search\SearchService;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
	private InMemorySearchIndex $index;

	private InMemoryTrendRepository $trends;

	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->index = new InMemorySearchIndex();
		$this->trends = new InMemoryTrendRepository();
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
	}

	public function testSearchGroupsIndexedRecordsAndUsesAStableCursor(): void
	{
		$service = $this->service();
		$this->index->upsert($this->record('post:1', 'hello php', 'alice', '2026-11-14T10:00:00Z', ['php']));
		$this->index->upsert($this->record('post:2', 'hello social', 'bob', '2026-11-14T09:59:00Z', ['social']));
		$this->index->upsert($this->record('post:3', 'hello cursor', 'carol', '2026-11-14T09:58:00Z'));

		$page = $service->search(null, 'hello', null, 2, null);
		self::assertCount(2, $page['statuses']);
		self::assertSame([], $page['accounts']);
		self::assertNotNull($page['next_cursor']);
		self::assertFalse($page['partial']);
		self::assertSame([], $page['hashtags'], 'Only hashtags matching the query are returned.');

		$second = $service->search(null, 'hello', null, 2, $page['next_cursor']);
		self::assertCount(1, $second['statuses']);
		self::assertNull($second['next_cursor']);

		$this->expectException(InvalidSearchCursorException::class);
		$service->search(null, 'different', null, 2, $page['next_cursor']);
	}

	public function testRemoteHandleIsResolvedOnceAndServedFromTheIndexAfterwards(): void
	{
		$remote = new class implements RemoteActorSearch {
			public int $calls = 0;

			public function lookup(RemoteHandle $handle): ?RemoteActorProfile
			{
				++$this->calls;

				return new RemoteActorProfile(
					actorId: 'https://remote.example/users/bob',
					handle: 'bob@remote.example',
					displayName: 'Bob',
					summary: 'Remote Bob',
					avatarUrl: 'https://remote.example/avatar.png',
					headerUrl: null,
					aliases: ['https://remote.example/users/robert'],
					fetchedAt: Time::parse('2026-11-14T10:00:00Z'),
				);
			}
		};
		$service = $this->service($remote);

		$first = $service->search(null, '@bob@remote.example', 'accounts', 10, null);
		self::assertCount(1, $first['accounts']);
		self::assertSame('remote', $first['accounts'][0]['source']);
		self::assertSame(1, $remote->calls);

		$second = $service->search(null, 'bob@remote.example', 'accounts', 10, null);
		self::assertCount(1, $second['accounts']);
		self::assertSame('https://remote.example/users/bob', $second['accounts'][0]['author_id']);
		self::assertSame(1, $remote->calls, 'A fresh cached profile is not fetched again.');
	}

	public function testRemoteFailureIsReportedAsPartialInsteadOfEmptyFediverse(): void
	{
		$remote = new class implements RemoteActorSearch {
			public function lookup(RemoteHandle $handle): ?RemoteActorProfile
			{
				throw new RemoteSearchException('webfinger_timeout', 'The remote lookup timed out.');
			}
		};
		$service = $this->service($remote);

		$page = $service->search(null, '@bob@remote.example', 'accounts', 10, null);

		self::assertTrue($page['partial']);
		self::assertSame('webfinger_timeout', $page['remote_error']);
		self::assertSame([], $page['accounts']);
	}

	public function testMutedAuthorsAreExcludedForTheSignedInViewer(): void
	{
		$mutes = new InMemoryMuteRepository();
		$muteService = new MuteService($mutes, $this->clock);
		$muteService->mute('bob', 'alice');
		$service = $this->service(null, $muteService);
		$this->index->upsert($this->record('post:alice', 'hello alice', 'alice', '2026-11-14T10:00:00Z'));
		$this->index->upsert($this->record('post:carol', 'hello carol', 'carol', '2026-11-14T09:59:00Z'));

		$page = $service->search('bob', 'hello', 'statuses', 10, null);

		self::assertSame(['post:carol'], array_column($page['statuses'], 'id'));
		self::assertSame(['post:alice', 'post:carol'], array_column(
			$service->search(null, 'hello', 'statuses', 10, null)['statuses'],
			'id',
		));
	}

	public function testTrendWindowAndInputValidationAreBounded(): void
	{
		$service = $this->service();
		$this->index->upsert($this->record('post:1', 'hello #php #php', 'alice', '2026-11-14T10:00:00Z', ['php']));
		$this->trends->record($this->index->find('post:1'));

		$trends = $service->trends(10, 86_400);
		self::assertSame(86_400, $trends['window_seconds']);
		self::assertSame([['hashtag' => 'php', 'count' => 1]], $trends['trends']);

		$queryPage = $service->search(null, 'php', 'hashtags', 10, null);
		self::assertSame([['hashtag' => 'php', 'count' => 1]], $queryPage['hashtags']);

		$this->expectException(\InvalidArgumentException::class);
		$service->search(null, '', null, 10, null);
	}

	private function service(
		?RemoteActorSearch $remote = null,
		?MuteService $mutes = null,
	): SearchService {
		return new SearchService(
			$this->index,
			$this->clock,
			$remote,
			$this->trends,
			null,
			$mutes,
		);
	}

	/**
	 * @param list<string> $hashtags
	 */
	private function record(
		string $id,
		string $content,
		string $authorId,
		string $publishedAt,
		array $hashtags = [],
	): SearchRecord {
		return SearchRecord::create(
			id: $id,
			kind: SearchKind::Post,
			source: SearchSource::Local,
			content: $content,
			publishedAt: Time::parse($publishedAt),
			cachedAt: Time::parse($publishedAt),
			authorId: $authorId,
			hashtags: $hashtags,
		);
	}
}
