<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\BlockedActorPolicy;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Events\EventBroker;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use PHPUnit\Framework\TestCase;

/**
 * The real inbound path: a signed POST /inbox changes the remote-post store,
 * and a later authenticated home-timeline read returns the change.
 *
 * The assertions deliberately go through the HTTP boundary instead of calling
 * the repositories directly; that is what proves the wiring, not just the
 * individual methods.
 */
final class InboundTimelineTest extends TestCase
{
	private const BOB = 'https://remote.example/users/bob';

	private const BOB_KEY = 'https://remote.example/users/bob#main-key';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private const NOTE_1 = 'https://remote.example/notes/1';

	private const NOTE_2 = 'https://remote.example/notes/2';

	private const NOTE_3 = 'https://remote.example/notes/3';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private InMemoryFollowingRepository $following;

	public static function setUpBeforeClass(): void
	{
		self::$remoteKey = RsaKeyPair::generate();
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->http = new FakeHttpClient();
		$this->following = new InMemoryFollowingRepository();
		$this->follow('alice', self::BOB);
	}

	public function testCreateDeleteAndAnnounceReachTheFollowerTimeline(): void
	{
		$app = $this->app();

		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-1',
			self::NOTE_1,
			'first remote post',
			'2026-11-11T10:01:00Z',
		))->status);
		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-2',
			self::NOTE_2,
			'second remote post',
			'2026-11-11T10:02:00Z',
		))->status);

		$feed = $this->feed($app);
		self::assertSame(200, $feed->status);
		self::assertSame(
			['https://remote.example/activities/create-2', 'https://remote.example/activities/create-1'],
			array_column($feed->decodedBody()['posts'], 'id'),
		);

		$duplicate = $this->deliver($app, $this->create(
			'https://remote.example/activities/create-1',
			self::NOTE_1,
			'first remote post',
			'2026-11-11T10:01:00Z',
		));
		self::assertSame(400, $duplicate->status);
		self::assertSame('replayed_activity', $duplicate->decodedBody()['error']['code']);
		self::assertCount(2, $this->feed($app)->decodedBody()['posts']);

		self::assertSame(202, $this->deliver($app, $this->delete(
			'https://remote.example/activities/delete-1',
			self::NOTE_1,
		))->status);

		$afterDelete = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $afterDelete);
		self::assertSame('https://remote.example/activities/create-2', $afterDelete[0]['id']);
		self::assertSame('second remote post', $afterDelete[0]['content']);

		self::assertSame(202, $this->deliver($app, $this->announce(
			'https://remote.example/activities/announce-1',
			self::NOTE_3,
			'boosted remote note',
			'2026-11-11T10:03:00Z',
		))->status);

		$withBoost = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(2, $withBoost);
		self::assertSame('https://remote.example/activities/announce-1', $withBoost[0]['id']);
		self::assertSame('boost', $withBoost[0]['kind']);
		self::assertSame(self::NOTE_3, $withBoost[0]['object_id']);
		self::assertSame(self::BOB, $withBoost[0]['boosted_by']);
		self::assertSame('boosted remote note', $withBoost[0]['content']);

		self::assertSame(202, $this->deliver($app, $this->delete(
			'https://remote.example/activities/delete-2',
			self::NOTE_2,
		))->status);

		$onlyBoost = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $onlyBoost);
		self::assertSame('boost', $onlyBoost[0]['kind']);

		self::assertSame(202, $this->deliver($app, $this->delete(
			'https://remote.example/activities/delete-announce',
			'https://remote.example/activities/announce-1',
		))->status);

		self::assertSame([], $this->feed($app)->decodedBody()['posts']);
	}

	public function testAnAcceptedRemoteUpdatePublishesTheEditedObjectToTheHomeAudience(): void
	{
		$published = [];
		$broker = $this->createMock(EventBroker::class);
		$broker->expects(self::exactly(2))->method('publish')->willReturnCallback(
			static function (StreamEventDraft $event) use (&$published): void { $published[] = $event; },
		);
		$app = $this->app(events: $broker);
		$create = $this->create(
			'https://remote.example/activities/create-streamed',
			self::NOTE_1,
			'original text',
			'2026-11-11T10:01:00Z',
		);
		$create['object']['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
		self::assertSame(202, $this->deliver($app, $create)->status);

		$update = $this->update(
			'https://remote.example/activities/update-streamed',
			self::NOTE_1,
			'edited text',
			'2026-11-11T10:02:00Z',
		);
		$update['object']['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
		self::assertSame(202, $this->deliver($app, $update)->status);

		self::assertSame(['status.created', 'status.updated'], array_column($published, 'name'));
		self::assertSame(['status_id' => self::NOTE_1], $published[1]->payload);
		self::assertSame(['alice'], $published[1]->recipientIds);
		self::assertSame('edited text', $this->feed($app)->decodedBody()['posts'][0]['content']);
	}

	public function testSignedInHomeFeedHidesRemoteRepliesButKeepsRootPosts(): void
	{
		$app = $this->app();
		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-root',
			self::NOTE_1,
			'root post',
			'2026-11-11T10:01:00Z',
		))->status);
		$reply = $this->create(
			'https://remote.example/activities/create-reply',
			self::NOTE_2,
			'reply post',
			'2026-11-11T10:02:00Z',
		);
		$reply['object']['inReplyTo'] = ['type' => 'Link', 'href' => self::NOTE_1];
		self::assertSame(202, $this->deliver($app, $reply)->status);

		self::assertSame([self::NOTE_1], array_column($this->feed($app)->decodedBody()['posts'], 'object_id'));
	}

	public function testUndoAnnounceRemovesTheBoostAndIsIdempotent(): void
	{
		$app = $this->app();
		$announce = $this->announce(
			'https://remote.example/activities/announce-undo',
			self::NOTE_3,
			'boosted remote note',
			'2026-11-11T10:03:00Z',
		);

		self::assertSame(202, $this->deliver($app, $announce)->status);
		self::assertCount(1, $this->feed($app)->decodedBody()['posts']);

		$undo = [
			'id' => 'https://remote.example/activities/undo-announce',
			'type' => 'Undo',
			'actor' => self::BOB,
			'object' => $announce,
		];

		self::assertSame(202, $this->deliver($app, $undo)->status);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);

		// A retried Undo after a lost response must not fail when the boost is
		// already gone.
		$retry = array_merge($undo, ['id' => 'https://remote.example/activities/undo-announce-2']);
		self::assertSame(202, $this->deliver($app, $retry)->status);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);
	}

	public function testUndoAnnounceAcceptsTheAnnounceIdWithoutAnEmbeddedActivity(): void
	{
		$app = $this->app();
		$announce = $this->announce(
			'https://remote.example/activities/announce-id-only',
			self::NOTE_3,
			'boosted remote note',
			'2026-11-11T10:03:00Z',
		);

		self::assertSame(202, $this->deliver($app, $announce)->status);

		$undo = [
			'id' => 'https://remote.example/activities/undo-announce-id-only',
			'type' => 'Undo',
			'actor' => self::BOB,
			'object' => $announce['id'],
		];

		self::assertSame(202, $this->deliver($app, $undo)->status);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);
	}

	public function testBlockingAnActorHidesItsStoredPostsAndUnblockingRestoresThem(): void
	{
		$app = $this->app();

		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-blocked',
			self::NOTE_1,
			'blocked later',
			'2026-11-11T10:01:00Z',
		))->status);
		self::assertCount(1, $this->feed($app)->decodedBody()['posts']);

		$blocked = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/blocks',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $blocked->status);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);

		$blockId = $blocked->decodedBody()['block']['id'];
		self::assertSame(
			204,
			$app->handle(new Request('DELETE', '/api/v1/blocks/' . $blockId, userId: 'alice'))->status,
		);
		self::assertSame(
			'blocked later',
			$this->feed($app)->decodedBody()['posts'][0]['content'],
		);
	}

	public function testAnUnfollowedActorChangesNothing(): void
	{
		$app = $this->app(new InMemoryFollowingRepository());

		$response = $this->deliver($app, $this->create(
			'https://remote.example/activities/unfollowed-create',
			self::NOTE_1,
			'not for us',
			'2026-11-11T10:01:00Z',
		));

		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);
	}

	public function testABlockedActorChangesNothing(): void
	{
		$bob = self::BOB;
		$app = $this->app($this->following, new class($bob) implements BlockedActorPolicy {
			public function __construct(
				private readonly string $bob,
			) {
			}

			public function isBlocked(string $actorId): bool
			{
				return $actorId === $this->bob;
			}
		});

		$response = $this->deliver($app, $this->create(
			'https://remote.example/activities/blocked-create',
			self::NOTE_1,
			'blocked',
			'2026-11-11T10:01:00Z',
		));

		self::assertSame(403, $response->status);
		self::assertSame('blocked_actor', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);
	}

	public function testAnUpdateReplacesTheStoredVersion(): void
	{
		$app = $this->app();
		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-1',
			self::NOTE_1,
			'first version',
			'2026-11-11T10:01:00Z',
		))->status);

		self::assertSame(202, $this->deliver($app, $this->update(
			'https://remote.example/activities/update-1',
			self::NOTE_1,
			'edited version',
			'2026-11-11T10:05:00Z',
		))->status);

		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $posts);
		self::assertSame('edited version', $posts[0]['content']);
	}

	public function testAnUpdateThatArrivesBeforeItsCreateIsAppliedWhenThePostArrives(): void
	{
		$app = $this->app();

		self::assertSame(202, $this->deliver($app, $this->update(
			'https://remote.example/activities/update-early',
			self::NOTE_2,
			'already edited',
			'2026-11-11T10:05:00Z',
		))->status);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);

		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-1',
			self::NOTE_2,
			'original text',
			'2026-11-11T10:01:00Z',
		))->status);

		$posts = $this->feed($app)->decodedBody()['posts'];
		self::assertCount(1, $posts);
		self::assertSame('already edited', $posts[0]['content']);
	}

	public function testAnOlderUpdateDoesNotOverwriteANewerOne(): void
	{
		$app = $this->app();
		$this->deliver($app, $this->create(
			'https://remote.example/activities/create-1',
			self::NOTE_1,
			'first version',
			'2026-11-11T10:01:00Z',
		));

		self::assertSame(202, $this->deliver($app, $this->update(
			'https://remote.example/activities/update-2',
			self::NOTE_1,
			'newest version',
			'2026-11-11T10:09:00Z',
		))->status);
		self::assertSame(202, $this->deliver($app, $this->update(
			'https://remote.example/activities/update-1',
			self::NOTE_1,
			'stale version',
			'2026-11-11T10:05:00Z',
		))->status);

		self::assertSame('newest version', $this->feed($app)->decodedBody()['posts'][0]['content']);
	}

	public function testAnUpdateForSomebodyElsesObjectIsRefused(): void
	{
		$app = $this->app();
		$update = $this->update(
			'https://remote.example/activities/update-1',
			self::NOTE_1,
			'not mine',
			'2026-11-11T10:05:00Z',
		);
		$update['object']['attributedTo'] = 'https://mallory.example/users/eve';

		$response = $this->deliver($app, $update);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->feed($app)->decodedBody()['posts']);
	}

	public function testAnUpdateWithoutAnObjectIsRefused(): void
	{
		$response = $this->deliver($this->app(), [
			'id' => 'https://remote.example/activities/update-1',
			'type' => 'Update',
			'actor' => self::BOB,
		]);

		self::assertSame(400, $response->status);
		self::assertSame('invalid_activity', $response->decodedBody()['error']['code']);
	}

	public function testAnUpdateWithEmptyContentIsRefused(): void
	{
		$app = $this->app();
		$this->deliver($app, $this->create(
			'https://remote.example/activities/create-1',
			self::NOTE_1,
			'first version',
			'2026-11-11T10:01:00Z',
		));

		$response = $this->deliver($app, $this->update(
			'https://remote.example/activities/update-1',
			self::NOTE_1,
			'   ',
			'2026-11-11T10:05:00Z',
		));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_activity', $response->decodedBody()['error']['code']);
		self::assertSame('first version', $this->feed($app)->decodedBody()['posts'][0]['content']);
	}

	private function app(
		?InMemoryFollowingRepository $following = null,
		?BlockedActorPolicy $blockedActors = null,
		?EventBroker $events = null,
	): Application {
		return Application::inMemory(
			clock: $this->clock,
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::BOB_KEY => new RemoteActor(
					self::BOB,
					self::BOB_KEY,
					self::BOB_INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			followingRepository: $following ?? $this->following,
			blockedActors: $blockedActors,
			eventBroker: $events,
		);
	}

	private function feed(Application $app): Response
	{
		return $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(Application $app, array $activity): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::BOB_KEY,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		return $app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $body,
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function create(string $activityId, string $noteId, string $content, string $publishedAt): array
	{
		return [
			'id' => $activityId,
			'type' => 'Create',
			'actor' => self::BOB,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'published' => $publishedAt,
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'content' => $content,
				'published' => $publishedAt,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function announce(string $activityId, string $noteId, string $content, string $publishedAt): array
	{
		return [
			'id' => $activityId,
			'type' => 'Announce',
			'actor' => self::BOB,
			'published' => $publishedAt,
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'content' => $content,
				'published' => $publishedAt,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function update(string $activityId, string $noteId, string $content, string $updatedAt): array
	{
		return [
			'id' => $activityId,
			'type' => 'Update',
			'actor' => self::BOB,
			'published' => $updatedAt,
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'content' => $content,
				'published' => '2026-11-11T10:01:00Z',
				'updated' => $updatedAt,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function delete(string $activityId, string $noteId): array
	{
		return [
			'id' => $activityId,
			'type' => 'Delete',
			'actor' => self::BOB,
			'object' => [
				'id' => $noteId,
				'type' => 'Tombstone',
			],
		];
	}

	private function follow(string $localUserId, string $actorId): void
	{
		$this->following->insert(new FollowedActor(
			'f1',
			$localUserId,
			$actorId,
			self::BOB_INBOX,
			Time::parse('2026-11-11T09:00:00Z'),
		));
	}
}
