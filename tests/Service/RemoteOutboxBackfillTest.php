<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\BlockedActorPolicy;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Service\Federation\RemoteBackfillException;
use AlohaSocial\Core\Service\Federation\RemoteOutboxBackfill;
use AlohaSocial\Core\Service\Federation\RemotePostFactory;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class RemoteOutboxBackfillTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const OUTBOX = 'https://remote.example/users/bob/outbox';

	private FakeHttpClient $http;

	private InMemoryRemotePostRepository $posts;

	private InMemoryFollowingRepository $following;

	private RemoteOutboxBackfill $backfill;

	protected function setUp(): void
	{
		$this->http = new FakeHttpClient();
		$this->posts = new InMemoryRemotePostRepository();
		$this->following = new InMemoryFollowingRepository();
		$this->following->insert(new FollowedActor(
			'f1',
			'alice',
			self::ACTOR,
			'https://remote.example/users/bob/inbox',
			Time::parse('2026-11-11T09:00:00Z'),
		));

		$clock = new FrozenClock(Time::parse('2026-11-11T12:00:00Z'));
		$this->backfill = new RemoteOutboxBackfill(
			$this->http,
			$this->posts,
			new RemotePostFactory($clock),
			$this->following,
		);
	}

	public function testFollowsFirstThenNextPageAndDeduplicatesActivities(): void
	{
		$this->queue(['type' => 'OrderedCollection', 'first' => self::OUTBOX . '?page=1']);
		$this->queue([
			'type' => 'OrderedCollectionPage',
			'orderedItems' => [
				$this->create('https://remote.example/activities/create-1', 'https://remote.example/notes/1', 'one'),
				$this->announce('https://remote.example/activities/announce-1', 'https://remote.example/notes/3', 'three'),
				$this->create('https://remote.example/activities/foreign', 'https://remote.example/notes/4', 'four', 'https://other.example/users/eve'),
				'not an object',
			],
			'next' => self::OUTBOX . '?page=2',
		]);
		$this->queue([
			'type' => 'OrderedCollectionPage',
			'orderedItems' => [
				$this->create('https://remote.example/activities/create-2', 'https://remote.example/notes/2', 'two'),
				$this->create('https://remote.example/activities/create-1', 'https://remote.example/notes/1', 'one'),
			],
		]);

		$report = $this->backfill->backfill(self::ACTOR, self::OUTBOX);

		self::assertSame(3, $report->pages);
		self::assertSame(6, $report->items);
		self::assertSame(3, $report->stored);
		self::assertSame(1, $report->duplicates);
		self::assertSame(2, $report->skipped);
		self::assertSame([
			'pages' => 3,
			'items' => 6,
			'stored' => 3,
			'duplicates' => 1,
			'skipped' => 2,
		], $report->toArray());
		self::assertNotNull($this->posts->findByActivityId('https://remote.example/activities/create-2'));
	}

	public function testAnUnfollowedActorIsRefusedBeforeAnyFetch(): void
	{
		$service = new RemoteOutboxBackfill(
			$this->http,
			$this->posts,
			new RemotePostFactory(new FrozenClock(Time::parse('2026-11-11T12:00:00Z'))),
			new InMemoryFollowingRepository(),
		);

		try {
			$service->backfill(self::ACTOR, self::OUTBOX);
			self::fail('The unfollowed actor was accepted.');
		} catch (RemoteBackfillException $exception) {
			self::assertSame('not_followed', $exception->reason);
		}

		self::assertSame([], $this->http->requests);
	}

	public function testABlockedActorIsRefusedBeforeAnyFetch(): void
	{
		$service = new RemoteOutboxBackfill(
			$this->http,
			$this->posts,
			new RemotePostFactory(new FrozenClock(Time::parse('2026-11-11T12:00:00Z'))),
			$this->following,
			new class(self::ACTOR) implements BlockedActorPolicy {
				public function __construct(
					private readonly string $actorId,
				) {
				}

				public function isBlocked(string $actor): bool
				{
					return $actor === $this->actorId;
				}
			},
		);

		try {
			$service->backfill(self::ACTOR, self::OUTBOX);
			self::fail('The blocked actor was backfilled.');
		} catch (RemoteBackfillException $exception) {
			self::assertSame('blocked_actor', $exception->reason);
		}

		self::assertSame([], $this->http->requests);
	}

	public function testPaginationCannotLeaveTheOutboxOrigin(): void
	{
		$this->queue(['type' => 'OrderedCollection', 'first' => 'https://other.example/outbox?page=1']);

		try {
			$this->backfill->backfill(self::ACTOR, self::OUTBOX);
			self::fail('Cross-origin pagination was followed.');
		} catch (RemoteBackfillException $exception) {
			self::assertSame('pagination_scope', $exception->reason);
		}

		self::assertCount(1, $this->http->requests);
	}

	public function testTheItemLimitStopsInsideAPage(): void
	{
		$this->queue([
			'type' => 'OrderedCollection',
			'first' => [
				'type' => 'OrderedCollectionPage',
				'orderedItems' => [
					$this->create('https://remote.example/activities/create-1', 'https://remote.example/notes/1', 'one'),
					$this->create('https://remote.example/activities/create-2', 'https://remote.example/notes/2', 'two'),
					$this->create('https://remote.example/activities/create-3', 'https://remote.example/notes/3', 'three'),
				],
			],
		]);

		$report = $this->backfill->backfill(self::ACTOR, self::OUTBOX, maxPages: 10, maxItems: 2);

		self::assertSame(1, $report->pages);
		self::assertSame(2, $report->items);
		self::assertSame(2, $report->stored);
		self::assertNull($this->posts->findByActivityId('https://remote.example/activities/create-3'));
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function queue(array $document): void
	{
		$this->http->queueResponse(new HttpResponse(
			200,
			['content-type' => 'application/activity+json'],
			json_encode($document, JSON_THROW_ON_ERROR),
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function create(
		string $activityId,
		string $noteId,
		string $content,
		string $actor = self::ACTOR,
	): array {
		return [
			'id' => $activityId,
			'type' => 'Create',
			'actor' => $actor,
			'published' => '2026-11-11T10:00:00Z',
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => $actor,
				'content' => $content,
				'published' => '2026-11-11T10:00:00Z',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function announce(string $activityId, string $noteId, string $content): array
	{
		return [
			'id' => $activityId,
			'type' => 'Announce',
			'actor' => self::ACTOR,
			'published' => '2026-11-11T10:01:00Z',
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => self::ACTOR,
				'content' => $content,
				'published' => '2026-11-11T10:01:00Z',
			],
		];
	}
}
