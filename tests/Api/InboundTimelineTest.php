<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\BlockedActorPolicy;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostImporter;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\Visibility;
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

	private const CAROL = 'https://remote.example/users/carol';

	private const CAROL_KEY = 'https://remote.example/users/carol#main-key';

	private const CAROL_INBOX = 'https://remote.example/users/carol/inbox';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $carolKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private InMemoryFollowingRepository $following;

	public static function setUpBeforeClass(): void
	{
		self::$remoteKey = RsaKeyPair::generate();
		self::$carolKey = RsaKeyPair::generate();
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
		// An identical retry of a processed activity is an idempotent success.
		self::assertSame(202, $duplicate->status);
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

	public function testRemoteRepliesAndBoostsOfLocalPublicPostsCreateNotifications(): void
	{
		$app = $this->app();
		$local = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'local post', 'visibility' => 'public'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $local->status, $local->body);
		$localPostId = $local->decodedBody()['post']['id'];
		$localNoteId = 'https://localhost:8080/api/v1/posts/' . rawurlencode($localPostId);

		$reply = $this->create(
			'https://remote.example/activities/reply-to-local',
			'https://remote.example/notes/reply-to-local',
			'remote reply',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;
		self::assertSame(202, $this->deliver($app, $reply)->status);

		$boost = [
			'id' => 'https://remote.example/activities/boost-local',
			'type' => 'Announce',
			'actor' => self::BOB,
			'published' => '2026-11-11T10:02:00Z',
			'object' => $localNoteId,
		];
		self::assertSame(202, $this->deliver($app, $boost)->status);

		$page = $app->handle(new Request('GET', '/api/v1/notifications', userId: 'alice'));
		self::assertSame(200, $page->status);
		$notifications = $page->decodedBody()['notifications'];
		self::assertCount(2, $notifications);
		$byType = [];
		foreach ($notifications as $notification) {
			$byType[$notification['type']] = $notification;
			self::assertSame(self::BOB, $notification['actor_id']);
		}
		self::assertSame('https://remote.example/notes/reply-to-local', $byType['mention']['post_id']);
		self::assertSame($localPostId, $byType['reblog']['post_id']);
	}

	public function testRemoteBoostOfFollowersOnlyLocalPostDoesNotCreateNotification(): void
	{
		$app = $this->app();
		$local = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'private post', 'visibility' => 'followers'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $local->status, $local->body);
		$localNoteId = 'https://localhost:8080/api/v1/posts/' . rawurlencode($local->decodedBody()['post']['id']);
		self::assertSame(202, $this->deliver($app, [
			'id' => 'https://remote.example/activities/boost-private-local',
			'type' => 'Announce',
			'actor' => self::BOB,
			'object' => $localNoteId,
		])->status);

		self::assertSame([], $app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			userId: 'alice',
		))->decodedBody()['notifications']);
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

	public function testRemoteBoostNotificationsRespectLocalBlocksAndNotificationMutes(): void
	{
		$blocks = new InMemoryBlockRepository();
		$blocks->insert(new Block('block-bob', 'alice', self::BOB, $this->clock->now()));
		$this->assertRemoteBoostNotificationMissing($this->app(blockRepository: $blocks), 'blocked-local-post');

		$mutes = new InMemoryMuteRepository();
		$mutes->save(new Mute('alice', self::BOB, true, $this->clock->now()));
		$this->assertRemoteBoostNotificationMissing($this->app(muteRepository: $mutes), 'muted-local-post');
	}

	private function assertRemoteBoostNotificationMissing(Application $app, string $content): void
	{
		$local = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content, 'visibility' => 'public'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $local->status, $local->body);
		$localNoteId = 'https://localhost:8080/api/v1/posts/' . rawurlencode($local->decodedBody()['post']['id']);
		self::assertSame(202, $this->deliver($app, [
			'id' => 'https://remote.example/activities/boost-' . $content,
			'type' => 'Announce',
			'actor' => self::BOB,
			'object' => $localNoteId,
		])->status);

		self::assertSame([], $app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			userId: 'alice',
		))->decodedBody()['notifications']);
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

	public function testAnUnfollowedReplyToAPublicLocalPostIsAcceptedAndNotified(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		$localNoteId = $this->localPost($app, 'public');

		$reply = $this->create(
			'https://remote.example/activities/reply-unfollowed',
			'https://remote.example/notes/reply-unfollowed',
			'hello from afar',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;

		self::assertSame(202, $this->deliver($app, $reply)->status);

		$stored = $remotePosts->findPostByObjectId('https://remote.example/notes/reply-unfollowed');
		self::assertNotNull($stored);
		self::assertSame($localNoteId, $stored->inReplyToId);

		$notifications = $app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			userId: 'alice',
		))->decodedBody()['notifications'];
		self::assertCount(1, $notifications);
		self::assertSame('mention', $notifications[0]['type']);
		self::assertSame('https://remote.example/notes/reply-unfollowed', $notifications[0]['post_id']);

		// The reply is stored for the thread, but nobody follows the actor:
		// it must not appear in the home feed.
		self::assertNotContains(
			'https://remote.example/notes/reply-unfollowed',
			array_column($this->feed($app)->decodedBody()['posts'], 'object_id'),
		);
	}

	public function testAnUnfollowedReplyToAnUnlistedLocalPostIsAccepted(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		$localNoteId = $this->localPost($app, 'unlisted');

		$reply = $this->create(
			'https://remote.example/activities/reply-unlisted',
			'https://remote.example/notes/reply-unlisted',
			'reply to unlisted',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;

		self::assertSame(202, $this->deliver($app, $reply)->status);
		self::assertNotNull($remotePosts->findPostByObjectId('https://remote.example/notes/reply-unlisted'));
	}

	public function testAnUnfollowedReplyToAFollowersOnlyPostWaitsForTheFollow(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		$localNoteId = $this->localPost($app, 'followers');

		$reply = $this->create(
			'https://remote.example/activities/reply-private-1',
			'https://remote.example/notes/reply-private-1',
			'too early',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;

		$response = $this->deliver($app, $reply);
		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
		self::assertNull($remotePosts->findPostByObjectId('https://remote.example/notes/reply-private-1'));

		// Once the actor follows the author, the same reply is addressed and
		// entitled: it is accepted and notifies the author.
		self::assertSame(202, $this->deliver($app, [
			'id' => 'https://remote.example/activities/follow-alice',
			'type' => 'Follow',
			'actor' => self::BOB,
			'object' => 'https://localhost:8080/@alice',
		])->status);

		$reply['id'] = 'https://remote.example/activities/reply-private-2';
		$reply['object']['id'] = 'https://remote.example/notes/reply-private-2';
		self::assertSame(202, $this->deliver($app, $reply)->status);
		self::assertNotNull($remotePosts->findPostByObjectId('https://remote.example/notes/reply-private-2'));

		$notifications = $app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			userId: 'alice',
		))->decodedBody()['notifications'];
		self::assertCount(2, $notifications);
	}

	public function testAnUnfollowedReplyToADeletedLocalPostIsRefused(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		[$postId, $localNoteId] = $this->localPostWithId($app, 'public');

		$deleted = $app->handle(new Request('DELETE', '/api/v1/posts/' . $postId, userId: 'alice'));
		self::assertSame(204, $deleted->status, $deleted->body);

		$reply = $this->create(
			'https://remote.example/activities/reply-deleted',
			'https://remote.example/notes/reply-deleted',
			'too late',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;

		$response = $this->deliver($app, $reply);
		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
	}

	public function testAnUnfollowedReplyToAnUnknownParentIsRefused(): void
	{
		$app = $this->app(new InMemoryFollowingRepository());

		$reply = $this->create(
			'https://remote.example/activities/reply-unknown',
			'https://remote.example/notes/reply-unknown',
			'nowhere',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = 'https://elsewhere.example/notes/unknown';

		$response = $this->deliver($app, $reply);
		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
	}

	public function testAnUnfollowedReplyToAnUncachedPublicParentIsAcceptedThroughTheImporter(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(
			new InMemoryFollowingRepository(),
			remotePosts: $remotePosts,
			remotePostImporter: $this->importer($remotePosts, Visibility::Public),
		);

		$reply = $this->create(
			'https://remote.example/activities/reply-imported-parent',
			'https://remote.example/notes/reply-imported-parent',
			'nested reply',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = 'https://elsewhere.example/notes/parent';

		$response = $this->deliver($app, $reply);
		self::assertSame(202, $response->status, $response->body);

		$stored = $remotePosts->findPostByObjectId('https://remote.example/notes/reply-imported-parent');
		self::assertNotNull($stored);
		self::assertSame('https://elsewhere.example/notes/parent', $stored->inReplyToId);
	}

	public function testAnUnfollowedReplyToAnUncachedParentStaysRefusedWithoutAPublicAudience(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(
			new InMemoryFollowingRepository(),
			remotePosts: $remotePosts,
			remotePostImporter: $this->importer($remotePosts, Visibility::Followers),
		);

		$reply = $this->create(
			'https://remote.example/activities/reply-private-parent',
			'https://remote.example/notes/reply-private-parent',
			'should not land',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = 'https://elsewhere.example/notes/private-parent';

		$response = $this->deliver($app, $reply);
		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
		self::assertNull($remotePosts->findPostByObjectId('https://remote.example/notes/reply-private-parent'));
	}

	public function testAnUnfollowedBoostOfAnUncachedPublicRemotePostIsAcceptedThroughTheImporter(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(
			new InMemoryFollowingRepository(),
			remotePosts: $remotePosts,
			remotePostImporter: $this->importer($remotePosts, Visibility::Public),
		);

		$response = $this->deliver($app, [
			'id' => 'https://remote.example/activities/boost-imported',
			'type' => 'Announce',
			'actor' => self::BOB,
			'object' => 'https://elsewhere.example/notes/boost-target',
		]);

		self::assertSame(202, $response->status, $response->body);
		self::assertNotNull(
			$remotePosts->findByActivityId('https://remote.example/activities/boost-imported'),
		);
	}

	public function testACreateForAnImportedPlaceholderReplacesItWithTheDeliveredActivity(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$importer = $this->importer($remotePosts, Visibility::Public, self::BOB);
		$importer->ensure('https://remote.example/notes/delivered-later');

		$app = $this->app(remotePosts: $remotePosts, remotePostImporter: $importer);

		$response = $this->deliver($app, $this->create(
			'https://remote.example/activities/create-delivered-later',
			'https://remote.example/notes/delivered-later',
			'the real delivery',
			'2026-11-11T10:01:00Z',
		));
		self::assertSame(202, $response->status, $response->body);

		$stored = $remotePosts->findPostByObjectId('https://remote.example/notes/delivered-later');
		self::assertNotNull($stored);
		self::assertSame('https://remote.example/activities/create-delivered-later', $stored->activityId);
		self::assertSame('the real delivery', $stored->content);
	}

	public function testASecondCreateForTheSameObjectIsAnIdempotentNoOp(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(remotePosts: $remotePosts);

		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-once',
			'https://remote.example/notes/once',
			'only once',
			'2026-11-11T10:01:00Z',
		))->status);
		self::assertSame(202, $this->deliver($app, $this->create(
			'https://remote.example/activities/create-twice',
			'https://remote.example/notes/once',
			'only once',
			'2026-11-11T10:01:00Z',
		))->status);

		self::assertNull($remotePosts->findByActivityId('https://remote.example/activities/create-twice'));
		self::assertSame(
			'https://remote.example/activities/create-once',
			$remotePosts->findPostByObjectId('https://remote.example/notes/once')?->activityId,
		);
	}

	public function testAnUnfollowedReplyToABlockedAuthorsPostIsRefused(): void
	{
		$blocks = new InMemoryBlockRepository();
		$blocks->insert(new Block('block-bob-for-reply', 'alice', self::BOB, $this->clock->now()));
		$app = $this->app(new InMemoryFollowingRepository(), blockRepository: $blocks);
		$localNoteId = $this->localPost($app, 'public');

		$reply = $this->create(
			'https://remote.example/activities/reply-blocked-author',
			'https://remote.example/notes/reply-blocked-author',
			'blocked',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;

		$response = $this->deliver($app, $reply);
		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
	}

	public function testAnUnfollowedBoostOfAPublicLocalPostIsAccepted(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		$localNoteId = $this->localPost($app, 'public');

		$response = $this->deliver($app, [
			'id' => 'https://remote.example/activities/boost-unfollowed',
			'type' => 'Announce',
			'actor' => self::BOB,
			'object' => $localNoteId,
		]);

		self::assertSame(202, $response->status);
		self::assertNotNull($remotePosts->findByActivityId('https://remote.example/activities/boost-unfollowed'));

		$notifications = $app->handle(new Request(
			'GET',
			'/api/v1/notifications',
			userId: 'alice',
		))->decodedBody()['notifications'];
		self::assertCount(1, $notifications);
		self::assertSame('reblog', $notifications[0]['type']);
	}

	public function testAnUnfollowedBoostOfAFollowersOnlyLocalPostIsRefused(): void
	{
		$app = $this->app(new InMemoryFollowingRepository());
		$localNoteId = $this->localPost($app, 'followers');

		$response = $this->deliver($app, [
			'id' => 'https://remote.example/activities/boost-private',
			'type' => 'Announce',
			'actor' => self::BOB,
			'object' => $localNoteId,
		]);

		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
	}

	public function testAnUnfollowedActorUpdatesAndDeletesItsAddressedReply(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		$localNoteId = $this->localPost($app, 'public');

		$reply = $this->create(
			'https://remote.example/activities/reply-lifecycle',
			'https://remote.example/notes/reply-lifecycle',
			'original reply',
			'2026-11-11T10:01:00Z',
		);
		$reply['object']['inReplyTo'] = $localNoteId;
		self::assertSame(202, $this->deliver($app, $reply)->status);

		$update = $this->update(
			'https://remote.example/activities/update-lifecycle',
			'https://remote.example/notes/reply-lifecycle',
			'edited reply',
			'2026-11-11T10:05:00Z',
		);
		self::assertSame(202, $this->deliver($app, $update)->status);
		self::assertSame(
			'edited reply',
			$remotePosts->findPostByObjectId('https://remote.example/notes/reply-lifecycle')?->content,
		);

		self::assertSame(202, $this->deliver($app, $this->delete(
			'https://remote.example/activities/delete-lifecycle',
			'https://remote.example/notes/reply-lifecycle',
		))->status);
		self::assertNotNull(
			$remotePosts->findByActivityId('https://remote.example/activities/reply-lifecycle')?->deletedAt,
		);
	}

	public function testAnUnfollowedActorCannotChangeAnotherActorsStoredPost(): void
	{
		$remotePosts = new InMemoryRemotePostRepository();
		$app = $this->app(new InMemoryFollowingRepository(), remotePosts: $remotePosts);
		$localNoteId = $this->localPost($app, 'public');

		// Carol's addressed reply is stored even though nobody follows her.
		$carolReply = $this->create(
			'https://remote.example/activities/carol-reply',
			'https://remote.example/notes/carol-reply',
			'carol says hi',
			'2026-11-11T10:01:00Z',
			self::CAROL,
		);
		$carolReply['object']['inReplyTo'] = $localNoteId;
		self::assertSame(202, $this->deliverCarol($app, $carolReply)->status);

		// Bob is not followed either, and the post is not his: refused.
		$foreignUpdate = $this->update(
			'https://remote.example/activities/bob-edit-carol',
			'https://remote.example/notes/carol-reply',
			'vandalised',
			'2026-11-11T10:06:00Z',
		);
		$response = $this->deliver($app, $foreignUpdate);
		self::assertSame(403, $response->status);
		self::assertSame('not_followed', $response->decodedBody()['error']['code']);
		self::assertSame(
			'carol says hi',
			$remotePosts->findPostByObjectId('https://remote.example/notes/carol-reply')?->content,
		);

		// Carol herself may still edit her own reply.
		$ownUpdate = $this->update(
			'https://remote.example/activities/carol-edit',
			'https://remote.example/notes/carol-reply',
			'carol edits',
			'2026-11-11T10:07:00Z',
		);
		$ownUpdate['actor'] = self::CAROL;
		$ownUpdate['object']['attributedTo'] = self::CAROL;
		self::assertSame(202, $this->deliverCarol($app, $ownUpdate)->status);
		self::assertSame(
			'carol edits',
			$remotePosts->findPostByObjectId('https://remote.example/notes/carol-reply')?->content,
		);
	}

	private function app(
		?InMemoryFollowingRepository $following = null,
		?BlockedActorPolicy $blockedActors = null,
		?EventBroker $events = null,
		?InMemoryBlockRepository $blockRepository = null,
		?InMemoryMuteRepository $muteRepository = null,
		?InMemoryRemotePostRepository $remotePosts = null,
		?RemotePostImporter $remotePostImporter = null,
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
				self::CAROL_KEY => new RemoteActor(
					self::CAROL,
					self::CAROL_KEY,
					self::CAROL_INBOX,
					self::$carolKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			followingRepository: $following ?? $this->following,
			blockedActors: $blockedActors,
			blockRepository: $blockRepository,
			muteRepository: $muteRepository,
			eventBroker: $events,
			remotePostRepository: $remotePosts,
			remotePostImporter: $remotePostImporter,
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
		return $this->deliverAs($app, $activity, self::$remoteKey, self::BOB_KEY);
	}

	private function deliverCarol(Application $app, array $activity): Response
	{
		return $this->deliverAs($app, $activity, self::$carolKey, self::CAROL_KEY);
	}

	private function deliverAs(Application $app, array $activity, RsaKeyPair $key, string $keyId): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			$key,
			$keyId,
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
	 * Creates a local post for alice and returns its ActivityPub note id.
	 */
	private function localPost(Application $app, string $visibility): string
	{
		return $this->localPostWithId($app, $visibility)[1];
	}

	/**
	 * @return array{0: string, 1: string} the post id and its note id
	 */
	private function localPostWithId(Application $app, string $visibility): array
	{
		$local = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'local ' . $visibility . ' post', 'visibility' => $visibility], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $local->status, $local->body);
		$postId = $local->decodedBody()['post']['id'];

		return [$postId, 'https://localhost:8080/api/v1/posts/' . rawurlencode($postId)];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function create(
		string $activityId,
		string $noteId,
		string $content,
		string $publishedAt,
		string $actor = self::BOB,
	): array {
		return [
			'id' => $activityId,
			'type' => 'Create',
			'actor' => $actor,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'published' => $publishedAt,
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => $actor,
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

	private function importer(
		InMemoryRemotePostRepository $posts,
		Visibility $audience,
		string $author = self::CAROL,
	): RemotePostImporter {
		return new class ($posts, $audience, $author) implements RemotePostImporter {
			public function __construct(
				private readonly InMemoryRemotePostRepository $posts,
				private readonly Visibility $audience,
				private readonly string $author,
			) {
			}

			public function ensure(string $objectId): ?RemotePost
			{
				$post = new RemotePost(
					activityId: $objectId,
					objectId: $objectId,
					actorId: $this->author,
					kind: RemotePostKind::Post,
					content: 'imported remote post',
					contentWarning: null,
					publishedAt: new \DateTimeImmutable('2026-11-11T09:00:00Z'),
					objectAuthorId: $this->author,
					audience: $this->audience,
				);
				$this->posts->insert($post);

				return $post;
			}
		};
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
