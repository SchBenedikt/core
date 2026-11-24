<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Domain\Account\PdoAccountDataRepository;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\AccountMoveSchema;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Domain\Federation\PdoFollowingRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionTargetPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoPostProjection;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoAccountResolver;
use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateSchema;
use AlohaSocial\Core\Domain\Federation\PdoRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;
use AlohaSocial\Core\Domain\Federation\RemoteThreadSchema;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\AttachmentSchema;
use AlohaSocial\Core\Domain\Moderation\HeldPostSchema;
use AlohaSocial\Core\Domain\Moderation\ModerationActionSchema;
use AlohaSocial\Core\Domain\Moderation\ReportSchema;
use AlohaSocial\Core\Domain\Media\PdoAttachmentRepository;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\ConversationMuteSchema;
use AlohaSocial\Core\Domain\Notification\NotificationPolicySchema;
use AlohaSocial\Core\Domain\Search\SearchIndexSchema;
use AlohaSocial\Core\Domain\Search\TrendSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSenderSchema;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Domain\Notification\PdoNotificationRepository;
use AlohaSocial\Core\Domain\Timeline\AccountListSchema;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagSchema;
use AlohaSocial\Core\Domain\Post\BookmarkSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Domain\Post\QuoteGrantSchema;
use AlohaSocial\Core\Domain\Filter\FilterSchema;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSchema;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Post\PostPinSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSchema;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Jobs\PdoJobQueue;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionRejectedException;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use AlohaSocial\Core\Tests\Support\FixedInboxResolver;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use AlohaSocial\Core\Tests\Support\RejectingJobQueue;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The production composition root a host calls with its own ports.
 *
 * The same assertions the in-memory assembly gets: a post reaches SQL, a
 * follow reaches the peer, and an unconfigured inbox stays honest.
 */
final class ApplicationHostTest extends TestCase
{
	private const BOB = 'https://remote.example/users/bob';

	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';

	private static RsaKeyPair $key;

	private static RsaKeyPair $remoteKey;

	private FrozenClock $clock;

	public static function setUpBeforeClass(): void
	{
		self::$key = RsaKeyPair::generate();
		self::$remoteKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testForHostUsesSqlRepositoriesAndStillDeliversFollows(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$app = $this->host($database, $http);

		self::assertCount(234, $app->routes->routes());

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'from a host', 'visibility' => 'public'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $post->status);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));

		$search = $app->handle(new Request('GET', '/api/v1/search', ['q' => 'from a host']));
		self::assertSame(200, $search->status);
		self::assertCount(1, $search->decodedBody()['statuses']);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . SearchIndexSchema::TABLE));

		$follow = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/following',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB, 'inbox' => self::BOB_INBOX], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $follow->status);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . FollowingSchema::TABLE));
		self::assertCount(0, $http->requests);
		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, $app->worker->runOnce());
		self::assertCount(1, $http->requests);
		self::assertSame(self::BOB_INBOX, $http->requests[0]['url']);

		$stored = (new PdoFollowingRepository($database))->find('alice', self::BOB);
		self::assertNotNull($stored);
		self::assertSame(FollowState::Pending, $stored->state);
		(new PdoFollowingRepository($database))->updateState(
			$stored->id,
			FollowState::Pending,
			FollowState::Accepted,
		);

		$collection = $app->handle(new Request('GET', '/@alice/following', ['page' => '1']))->decodedBody();
		self::assertSame(1, $collection['totalItems']);
		self::assertSame([self::BOB], $collection['orderedItems']);

		$timeline = $app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody();
		self::assertSame(1, count($timeline['posts']));
	}

	public function testForHostWiresAccountExportAndErasureToTheSqlTables(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), blobStore: new InMemoryBlobStore());
		$database->execute(
			'INSERT INTO ' . PostSchema::TABLE . ' (id, author_id, content, visibility, published_at)'
			. " VALUES ('host-account-post', 'alice', 'export me', 'public', '2026-11-16T12:00:00Z')",
		);

		$export = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/account/export',
			userId: 'alice',
		));
		self::assertSame(200, $export->status, $export->body);
		self::assertSame(['host-account-post'], array_column(
			$export->decodedBody()['data'][PostSchema::TABLE],
			'id',
		));

		$erasure = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/account/data',
			userId: 'alice',
		));
		self::assertSame(200, $erasure->status, $erasure->body);
		self::assertSame(1, $erasure->decodedBody()['deleted'][PostSchema::TABLE]);
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));
	}

	public function testForHostFreezesWritesCancelsJobsAndKeepsTheDeletionResultInspectable(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), blobStore: new InMemoryBlobStore());
		$database->execute(
			'INSERT INTO ' . PostSchema::TABLE . ' (id, author_id, content, visibility, published_at)'
			. " VALUES ('deletion-post', 'alice', 'goes away', 'public', '2026-11-16T12:00:00Z')",
		);
		$app->queue->push('atproto.delete', ['user_id' => 'alice'], null, JobSubject::account('alice'));

		$active = $app->handle(new Request('GET', '/api/v1/account/deletion', userId: 'alice'));
		self::assertSame(200, $active->status);
		self::assertSame('active', $active->decodedBody()['state']);

		$repository = new PdoAccountDataRepository($database);
		$repository->beginDeletion('alice', Time::parse('2026-11-16T12:00:00Z'));

		$blocked = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: '{}',
			userId: 'alice',
		));
		self::assertSame(403, $blocked->status);
		self::assertSame('account_deletion_in_progress', $blocked->decodedBody()['error']['code']);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));

		$first = $app->handle(new Request('DELETE', '/api/v1/account/data', userId: 'alice'));
		self::assertSame(200, $first->status, $first->body);
		self::assertSame(1, $first->decodedBody()['deleted'][PostSchema::TABLE]);
		self::assertSame(0, $app->queue->pendingCount());

		$after = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: '{}',
			userId: 'alice',
		));
		self::assertSame(410, $after->status);
		self::assertSame('account_deleted', $after->decodedBody()['error']['code']);
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));

		$second = $app->handle(new Request('DELETE', '/api/v1/account/data', userId: 'alice'));
		self::assertSame(200, $second->status, $second->body);
		self::assertSame($first->body, $second->body);

		$status = $app->handle(new Request('GET', '/api/v1/account/deletion', userId: 'alice'));
		self::assertSame(200, $status->status);
		self::assertSame('erased', $status->decodedBody()['state']);
		self::assertNotNull($status->decodedBody()['completed_at']);

		$export = $app->handle(new Request('GET', '/api/v1/account/export', userId: 'alice'));
		self::assertSame(200, $export->status);
		self::assertSame('erased', $export->decodedBody()['deletion']['state']);
	}

	public function testForHostRetriesAQueuedFollowThroughTheSqlQueue(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$app = $this->host($database, $http);

		$http->queueResponse(new HttpResponse(503));
		$follow = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/following',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB, 'inbox' => self::BOB_INBOX], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $follow->status);
		self::assertSame(FollowState::Pending->value, $follow->decodedBody()['following']['state']);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(1, $app->queue->pendingCount());
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . JobSchema::TABLE));

		$this->clock->advance(30);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(0, $app->queue->pendingCount());
		self::assertCount(2, $http->requests);
		self::assertSame(
			FollowState::Pending->value,
			(new PdoFollowingRepository($database))->find('alice', self::BOB)?->state->value,
		);
	}

	public function testForHostPassesTheRemoteAtprotoTargetPolicyToInteractionService(): void
	{
		$policy = new class implements AtprotoInteractionTargetPolicy {
			public function allows(string $localUserId, AtprotoPostProjection $target): bool
			{
				return false;
			}
		};
		$app = $this->host(
			$this->database(),
			new FakeHttpClient(),
			atproto: true,
			atprotoRemoteTargetPolicy: $policy,
		);
		$target = new AtprotoPostProjection(
			'at://did:plc:bob/app.bsky.feed.post/post-bob',
			'bafy-bob-post',
			'did:plc:bob',
			null,
			'hello from Bob',
			Time::parse('2026-11-11T10:00:00Z'),
			null,
			null,
			[],
			null,
		);

		$this->expectException(AtprotoInteractionRejectedException::class);
		$app->atprotoInteractions?->likeRemote('alice', $target);
	}

	public function testForHostRollsBackThePostWhenTheQueueRejectsIt(): void
	{
		$database = $this->database();
		$app = $this->host(
			$database,
			new FakeHttpClient(),
			inboxResolver: new FixedInboxResolver([self::BOB_INBOX]),
			queue: new RejectingJobQueue(),
		);

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'no half post #rollback'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(500, $response->status);
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::HASHTAG_TABLE));
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . JobSchema::TABLE));
	}

	public function testForHostServesProfilesFollowsAndNotificationsFromSql(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), actors: ['alice', 'bob']);

		$profile = $app->handle(new Request('GET', '/api/v1/accounts/bob'));
		self::assertSame(200, $profile->status);
		self::assertNull($profile->decodedBody()['account']['relationship']);

		$follow = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));
		self::assertSame(200, $follow->status);
		self::assertTrue($follow->decodedBody()['relationship']['following']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowerSchema::TABLE . ' WHERE is_local = 1',
		));

		$viewed = $app->handle(new Request('GET', '/api/v1/accounts/bob', userId: 'alice'));
		self::assertSame(1, $viewed->decodedBody()['account']['followers_count']);
		self::assertTrue($viewed->decodedBody()['account']['relationship']['following']);

		$page = $app->handle(new Request('GET', '/api/v1/notifications', userId: 'bob'));
		self::assertSame(200, $page->status);
		self::assertSame(1, $page->decodedBody()['unread_count']);
		self::assertSame('follow', $page->decodedBody()['notifications'][0]['type']);
		$id = $page->decodedBody()['notifications'][0]['id'];

		$dismiss = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/notifications/' . rawurlencode($id) . '/dismiss',
			userId: 'bob',
		));
		self::assertSame(200, $dismiss->status);
		self::assertSame(0, $app->handle(
			new Request('GET', '/api/v1/notifications/unread_count', userId: 'bob'),
		)->decodedBody()['count']);
		self::assertNotNull($database->fetchValue(
			'SELECT read_at FROM ' . NotificationSchema::TABLE . ' WHERE id = ?',
			[$id],
		));

		$unfollow = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/accounts/bob/follow',
			userId: 'alice',
		));
		self::assertSame(200, $unfollow->status);
		self::assertFalse($unfollow->decodedBody()['relationship']['following']);
	}

	public function testForHostPersistsASignedCreateAndServesItInTheHomeTimeline(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$following = new PdoFollowingRepository($database);
		$following->insert(new FollowedActor(
			'f1',
			'alice',
			self::BOB,
			self::BOB_INBOX,
			Time::parse('2026-11-11T09:00:00Z'),
		));

		$app = $this->host($database, $http, new InMemoryActorResolver([
			'https://remote.example/users/bob#main-key' => new RemoteActor(
				self::BOB,
				'https://remote.example/users/bob#main-key',
				self::BOB_INBOX,
				self::$remoteKey->publicKeyPem(),
			),
		]));

		$create = [
			'id' => 'https://remote.example/activities/create-1',
			'type' => 'Create',
			'actor' => self::BOB,
			'object' => [
				'id' => 'https://remote.example/notes/1',
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'content' => 'stored in SQL',
				'published' => '2026-11-11T10:01:00Z',
			],
		];

		$response = $app->handle($this->signedInbox($create));

		self::assertSame(202, $response->status);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . RemotePostSchema::TABLE,
		));

		$feed = $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));

		self::assertSame(200, $feed->status);
		self::assertSame('stored in SQL', $feed->decodedBody()['posts'][0]['content']);
		self::assertSame('https://remote.example/activities/create-1', $feed->decodedBody()['posts'][0]['id']);

		$block = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/blocks',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => self::BOB], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $block->status);
		$blockId = $block->decodedBody()['block']['id'];
		self::assertSame([], $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'))->decodedBody()['posts']);

		self::assertSame(
			204,
			$app->handle(new Request('DELETE', '/api/v1/blocks/' . $blockId, userId: 'alice'))->status,
		);
		self::assertSame(
			'stored in SQL',
			$app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'))->decodedBody()['posts'][0]['content'],
		);
	}

	public function testForHostServesTheMastodonSubsetIdempotentlyOverSql(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true);
		$body = 'status=from+sql&visibility=public';

		$first = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Idempotency-Key' => 'sql-key-1',
			],
			body: $body,
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		$second = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Idempotency-Key' => 'sql-key-1',
			],
			body: $body,
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(200, $first->status);
		self::assertSame(200, $second->status);
		self::assertSame($first->decodedBody()['id'], $second->decodedBody()['id']);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PostSchema::TABLE));

		$search = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/search',
			query: ['q' => 'sql', 'type' => 'statuses'],
			userId: 'alice',
			scopes: ['read:search'],
		));
		self::assertSame(200, $search->status);
		self::assertCount(1, $search->decodedBody()['statuses']);
		self::assertSame($first->decodedBody()['id'], $search->decodedBody()['statuses'][0]['id']);

		$timeline = $app->handle(new Request('GET', '/api/mastodon/v1/timelines/public'));
		self::assertSame(200, $timeline->status);
		self::assertCount(1, $timeline->decodedBody());
		self::assertSame('<p>from sql</p>', $timeline->decodedBody()[0]['content']);

		$favourite = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $first->decodedBody()['id'] . '/favourite',
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(200, $favourite->status);
		self::assertTrue($favourite->decodedBody()['favourited']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . FavouriteSchema::TABLE,
		));

		$repeat = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $first->decodedBody()['id'] . '/favourite',
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(1, $repeat->decodedBody()['favourites_count']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . FavouriteSchema::TABLE,
		));
	}

	public function testForHostStoresAPrivateBookmarkOverSql(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true);

		$created = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['status' => 'bookmark over sql']),
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		$id = $created->decodedBody()['id'];

		$bookmark = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $id . '/bookmark',
			userId: 'alice',
			scopes: ['write:bookmarks'],
		));
		self::assertSame(200, $bookmark->status);
		self::assertTrue($bookmark->decodedBody()['bookmarked']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . BookmarkSchema::TABLE,
		));

		$list = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/bookmarks',
			userId: 'alice',
			scopes: ['read:bookmarks'],
		));
		self::assertSame(200, $list->status);
		self::assertCount(1, $list->decodedBody());
		self::assertSame($id, $list->decodedBody()[0]['id']);
		self::assertTrue($list->decodedBody()[0]['bookmarked']);

		$unbookmark = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $id . '/unbookmark',
			userId: 'alice',
			scopes: ['write:bookmarks'],
		));
		self::assertSame(200, $unbookmark->status);
		self::assertFalse($unbookmark->decodedBody()['bookmarked']);
		self::assertSame(0, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . BookmarkSchema::TABLE,
		));
	}

	public function testForHostStoresAMuteAndListsItOverSql(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true);

		$mute = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/accounts/' . rawurlencode(self::BOB) . '/mute',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['notifications' => '1']),
			userId: 'alice',
			scopes: ['write:mutes'],
		));
		self::assertSame(200, $mute->status);
		self::assertTrue($mute->decodedBody()['muting']);
		self::assertTrue($mute->decodedBody()['muting_notifications']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . MuteSchema::TABLE,
		));
		self::assertSame(self::BOB, $database->fetchValue(
			'SELECT actor_id FROM ' . MuteSchema::TABLE . ' WHERE user_id = :user_id',
			['user_id' => 'alice'],
		));

		$list = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/mutes',
			userId: 'alice',
			scopes: ['read:mutes'],
		));
		self::assertSame(200, $list->status);
		self::assertCount(1, $list->decodedBody());
		self::assertSame(self::BOB, $list->decodedBody()[0]['id']);

		$unmute = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/accounts/' . rawurlencode(self::BOB) . '/unmute',
			userId: 'alice',
			scopes: ['write:mutes'],
		));
		self::assertSame(200, $unmute->status);
		self::assertFalse($unmute->decodedBody()['muting']);
		self::assertSame(0, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . MuteSchema::TABLE,
		));
	}

	public function testForHostStoresListsAndUsesThemForListAndHomeTimelines(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true, actors: ['alice', 'bob']);

		$bobStatus = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['status' => 'from bob']),
			userId: 'bob',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $bobStatus->status);

		$follow = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/accounts/bob/follow',
			userId: 'alice',
			scopes: ['write:follows'],
		));
		self::assertSame(200, $follow->status);

		$created = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/lists',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['title' => 'SQL list', 'exclusive' => 'false']),
			userId: 'alice',
			scopes: ['write:lists'],
		));
		self::assertSame(200, $created->status);
		$listId = $created->decodedBody()['id'];

		$added = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/lists/' . $listId . '/accounts',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['account_ids' => ['bob']]),
			userId: 'alice',
			scopes: ['write:lists'],
		));
		self::assertSame(200, $added->status);

		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . AccountListSchema::TABLE));
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . AccountListSchema::MEMBER_TABLE));

		$listTimeline = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/timelines/list/' . $listId,
			userId: 'alice',
			scopes: ['read:lists'],
		));
		self::assertSame(200, $listTimeline->status);
		self::assertSame([$bobStatus->decodedBody()['id']], array_column($listTimeline->decodedBody(), 'id'));

		$home = $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));
		self::assertContains($bobStatus->decodedBody()['id'], array_column($home->decodedBody()['posts'], 'id'));

		$exclusive = $app->handle(new Request(
			method: 'PUT',
			path: '/api/mastodon/v1/lists/' . $listId,
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['exclusive' => 'true']),
			userId: 'alice',
			scopes: ['write:lists'],
		));
		self::assertSame(200, $exclusive->status);

		$home = $app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));
		self::assertNotContains($bobStatus->decodedBody()['id'], array_column($home->decodedBody()['posts'], 'id'));

		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/mastodon/v1/lists/' . $listId,
			userId: 'alice',
			scopes: ['write:lists'],
		));
		self::assertSame(200, $deleted->status);
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . AccountListSchema::TABLE));
		self::assertSame(0, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . AccountListSchema::MEMBER_TABLE));
	}

	public function testForHostStoresAPollWithItsStatusOverSql(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true);

		$created = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query([
				'status' => 'poll over sql',
				'poll' => [
					'options' => ['Yes', 'No'],
					'expires_in' => '3600',
				],
			]),
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(200, $created->status);
		self::assertSame(1, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PollSchema::TABLE));
		self::assertSame(2, (int) $database->fetchValue('SELECT COUNT(*) FROM ' . PollSchema::OPTION_TABLE));
		self::assertSame('Yes', $database->fetchValue('SELECT title FROM ' . PollSchema::OPTION_TABLE . ' WHERE position = 0'));

		$status = $created->decodedBody();
		$shown = $app->handle(new Request(
			'GET',
			'/api/mastodon/v1/statuses/' . $status['id'],
		));
		self::assertSame(200, $shown->status);
		self::assertSame($status['poll'], $shown->decodedBody()['poll']);
		self::assertSame('2026-11-11T11:00:00.000Z', $shown->decodedBody()['poll']['expires_at']);
	}

	public function testForHostVotesInAPollOverSql(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true);

		$created = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query([
				'status' => 'vote over sql',
				'poll' => [
					'options' => ['Yes', 'No'],
					'expires_in' => '3600',
				],
			]),
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $created->status);
		$pollId = $created->decodedBody()['poll']['id'];

		$voted = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/polls/' . rawurlencode($pollId) . '/votes',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: 'choices[0]=1',
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(200, $voted->status);
		self::assertSame([1], $voted->decodedBody()['own_votes']);
		self::assertSame(1, $voted->decodedBody()['votes_count']);
		self::assertSame(1, $voted->decodedBody()['voters_count']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . PollSchema::TABLE . ' WHERE votes_count = 1',
		));
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT votes_count FROM ' . PollSchema::OPTION_TABLE . ' WHERE post_id = :post_id AND position = 1',
			['post_id' => $created->decodedBody()['id']],
		));
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . PollSchema::VOTE_TABLE,
		));
	}

	public function testForHostDegradesPollReadsButRefusesVotesWithoutTheVoteTable(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient(), mastodon: true);

		$created = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query([
				'status' => 'poll without votes table',
				'poll' => [
					'options' => ['Yes', 'No'],
					'expires_in' => '3600',
				],
			]),
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $created->status);
		$status = $created->decodedBody();

		$database->execute('DROP TABLE ' . PollSchema::VOTE_TABLE);

		$read = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/statuses/' . $status['id'],
			userId: 'alice',
		));
		self::assertSame(200, $read->status);
		self::assertFalse($read->decodedBody()['poll']['voted']);

		$pollRead = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/polls/' . rawurlencode($status['poll']['id']),
			userId: 'alice',
		));
		self::assertSame(200, $pollRead->status);
		self::assertFalse($pollRead->decodedBody()['voted']);
		self::assertSame([], $pollRead->decodedBody()['own_votes']);

		$vote = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/polls/' . rawurlencode($status['poll']['id']) . '/votes',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: 'choices[0]=1',
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(503, $vote->status);
		self::assertSame('polls_not_configured', $vote->decodedBody()['error']['code']);
	}

	public function testForHostReportsInstanceMetadataAndSqlStats(): void
	{
		$database = $this->database();
		(new PdoFollowingRepository($database))->insert(new FollowedActor(
			'f-stats',
			'alice',
			self::BOB,
			self::BOB_INBOX,
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$app = $this->host(
			$database,
			new FakeHttpClient(),
			new InMemoryActorResolver([
				'https://remote.example/users/bob#main-key' => new RemoteActor(
					self::BOB,
					'https://remote.example/users/bob#main-key',
					self::BOB_INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			mastodon: true,
		);

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'a local post', 'visibility' => 'public'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $post->status);

		$create = [
			'id' => 'https://remote.example/activities/create-stats',
			'type' => 'Create',
			'actor' => self::BOB,
			'object' => [
				'id' => 'https://remote.example/notes/stats',
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'content' => 'a remote post',
				'published' => '2026-11-11T10:01:00Z',
			],
		];
		$inbox = $app->handle($this->signedInbox($create));
		self::assertSame(202, $inbox->status, $inbox->body);

		$instance = $app->handle(new Request('GET', '/api/mastodon/v1/instance'));

		self::assertSame(200, $instance->status);
		self::assertSame('Aloha Social', $instance->decodedBody()['title']);
		self::assertSame([
			'user_count' => 1,
			'status_count' => 1,
			'domain_count' => 1,
		], $instance->decodedBody()['stats']);
	}

	public function testForHostStoresARemoteFollowNotificationOverSql(): void
	{
		$database = $this->database();
		$app = $this->host(
			$database,
			new FakeHttpClient(),
			new InMemoryActorResolver([
				'https://remote.example/users/bob#main-key' => new RemoteActor(
					self::BOB,
					'https://remote.example/users/bob#main-key',
					self::BOB_INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			mastodon: true,
		);

		$response = $app->handle($this->signedInbox([
			'id' => 'https://remote.example/activities/follow-sql',
			'type' => 'Follow',
			'actor' => self::BOB,
			'object' => 'https://localhost:8080/@alice',
		]));
		self::assertSame(202, $response->status);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . NotificationSchema::TABLE,
		));

		$notifications = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/notifications',
			userId: 'alice',
			scopes: ['read:notifications'],
		));

		self::assertSame(200, $notifications->status);
		self::assertCount(1, $notifications->decodedBody());
		self::assertSame('follow', $notifications->decodedBody()[0]['type']);
		self::assertSame(self::BOB, $notifications->decodedBody()[0]['account']['id']);

		$unread = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/notifications/unread_count',
			userId: 'alice',
			scopes: ['read:notifications'],
		));
		self::assertSame(200, $unread->status);
		self::assertSame(1, $unread->decodedBody()['count']);

		$dismiss = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/notifications/' . rawurlencode($notifications->decodedBody()[0]['id']) . '/dismiss',
			userId: 'alice',
			scopes: ['write:notifications'],
		));
		self::assertSame(200, $dismiss->status);
		self::assertNotNull($database->fetchValue(
			'SELECT read_at FROM ' . NotificationSchema::TABLE,
		));

		$afterDismiss = $app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/notifications',
			userId: 'alice',
			scopes: ['read:notifications'],
		));
		self::assertSame([], $afterDismiss->decodedBody());

		$clear = $app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/notifications/clear',
			userId: 'alice',
			scopes: ['write:notifications'],
		));
		self::assertSame(200, $clear->status);
	}

	public function testForHostDeletesTheRemoteAtprotoRecordAfterALocalDelete(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$app = $this->host(
			$database,
			$http,
			atproto: true,
			atprotoAccounts: new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token'),
			]),
		);

		$http->queueResponseFactory(static function (string $method, string $url, array $headers, string $body): HttpResponse {
			$request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
			$uri = 'at://' . $request['repo'] . '/' . $request['collection'] . '/' . $request['rkey'];

			return new HttpResponse(200, [], json_encode(['uri' => $uri, 'cid' => 'bafy-record'], JSON_THROW_ON_ERROR));
		});
		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'sql delete'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $post->status);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . AtprotoPublicationSchema::TABLE,
		));
		self::assertSame('bafy-record', $database->fetchValue(
			'SELECT cid FROM ' . AtprotoPublicationSchema::TABLE,
		));

		$http->queueResponse(new HttpResponse(200, [], '{}'));
		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/posts/' . $post->decodedBody()['post']['id'],
			userId: 'alice',
		));
		self::assertSame(204, $deleted->status);
		self::assertSame(1, $app->worker->runOnce());
		self::assertSame(0, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . AtprotoPublicationSchema::TABLE,
		));
		self::assertSame(
			'https://pds.example/xrpc/com.atproto.repo.deleteRecord',
			$http->requests[1]['url'],
		);
	}

	public function testForHostLeavesTheInboxAt503WithoutAnActorResolver(): void
	{
		$app = $this->host($this->database(), new FakeHttpClient());

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			body: '{"type":"Follow"}',
		));

		self::assertSame(503, $response->status);
		self::assertSame('inbox_not_configured', $response->decodedBody()['error']['code']);

		self::assertSame(200, $app->handle(new Request('GET', '/@alice'))->status);
	}

	public function testForHostPersistsFollowedHashtagsAndServesTheirTimeline(): void
	{
		$database = $this->database();
		$app = $this->host($database, new FakeHttpClient());

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'stored #sql', 'visibility' => 'public'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $post->status);

		$follow = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/followed_tags',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['tag' => '#SQL'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $follow->status);
		self::assertSame('sql', $follow->decodedBody()['tag']);
		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowedHashtagSchema::TABLE,
		));

		$timeline = $app->handle(new Request(
			'GET',
			'/api/v1/timelines/followed_tags',
			userId: 'alice',
		));
		self::assertSame(200, $timeline->status);
		self::assertSame(['sql'], $timeline->decodedBody()['tags']);
		self::assertSame(
			[$post->decodedBody()['post']['id']],
			array_column($timeline->decodedBody()['posts'], 'id'),
		);

		$unfollow = $app->handle(new Request(
			'DELETE',
			'/api/v1/followed_tags/sql',
			userId: 'alice',
		));
		self::assertSame(200, $unfollow->status);
		self::assertSame(0, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . FollowedHashtagSchema::TABLE,
		));
	}

	public function testForHostRunsTheCleanupJobOverSqlWithoutTouchingFreshRows(): void
	{
		$database = $this->database();
		$blobs = new InMemoryBlobStore();
		$app = $this->host($database, new FakeHttpClient(), blobStore: $blobs);
		$attachments = new PdoAttachmentRepository($database);

		$oldOrphan = new Attachment(
			id: 'old-orphan',
			ownerId: 'alice',
			storageKey: 'media/old-orphan/original.png',
			thumbnailKey: 'media/old-orphan/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse('2026-09-01T00:00:00Z'),
		);
		$oldLinked = new Attachment(
			id: 'old-linked',
			ownerId: 'alice',
			storageKey: 'media/old-linked/original.png',
			thumbnailKey: 'media/old-linked/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse('2026-09-01T00:00:00Z'),
		);
		$remoteLinked = new Attachment(
			id: 'remote-linked',
			ownerId: 'alice',
			storageKey: 'media/remote-linked/original.png',
			thumbnailKey: 'media/remote-linked/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse('2026-09-01T00:00:00Z'),
		);
		$fresh = new Attachment(
			id: 'fresh',
			ownerId: 'alice',
			storageKey: 'media/fresh/original.png',
			thumbnailKey: 'media/fresh/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse('2026-11-11T10:00:00Z'),
		);
		foreach ([$oldOrphan, $oldLinked, $remoteLinked, $fresh] as $attachment) {
			$attachments->insert($attachment);
			$blobs->put($attachment->storageKey, 'original');
			$blobs->put($attachment->thumbnailKey, 'thumbnail');
		}

		$remotePosts = new PdoRemotePostRepository($database);
		$remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/cleanup',
			objectId: 'https://remote.example/notes/cleanup',
			actorId: 'https://remote.example/users/bob',
			kind: RemotePostKind::Post,
			content: 'remote post with a shared image',
			contentWarning: null,
			publishedAt: Time::parse('2026-09-01T00:00:00Z'),
			objectAuthorId: 'https://remote.example/users/bob',
		));
		$remotePosts->linkAttachments('https://remote.example/activities/cleanup', ['remote-linked']);

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'linked media',
				'visibility' => 'public',
				'attachment_ids' => ['old-linked'],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $post->status, $post->body);

		$notifications = new PdoNotificationRepository($database);
		$notifications->insert(new Notification(
			'old-read',
			'alice',
			'bob',
			NotificationType::Follow,
			null,
			Time::parse('2026-09-01T00:00:00Z'),
			Time::parse('2026-09-01T00:00:00Z'),
		));
		$notifications->insert(new Notification(
			'old-unread',
			'alice',
			'bob',
			NotificationType::Follow,
			null,
			Time::parse('2026-09-01T00:00:00Z'),
		));
		$notifications->insert(new Notification(
			'fresh-read',
			'alice',
			'bob',
			NotificationType::Follow,
			null,
			Time::parse('2026-11-11T10:00:00Z'),
			Time::parse('2026-11-11T10:00:00Z'),
		));

		$threads = new PdoRemoteThreadRepository($database);
		$threads->upsert(new RemoteThreadPost(
			'https://remote.example/notes/old',
			'https://remote.example/users/bob',
			'old thread node',
			Time::parse('2026-09-01T00:00:00Z'),
			Time::parse('2026-09-01T00:00:00Z'),
		));
		$threads->upsert(new RemoteThreadPost(
			'https://remote.example/notes/fresh',
			'https://remote.example/users/bob',
			'fresh thread node',
			Time::parse('2026-11-11T10:00:00Z'),
			Time::parse('2026-11-11T10:00:00Z'),
		));

		$app->queue->push(JobType::CLEANUP, [
			'kind' => 'all',
			'media_retention_days' => 30,
			'notification_retention_days' => 30,
			'thread_retention_days' => 30,
			'limit' => 100,
		]);
		self::assertSame(1, $app->worker->runOnce());

		self::assertSame(3, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . AttachmentSchema::TABLE,
		));
		self::assertNull($attachments->find('old-orphan'));
		self::assertNotNull($attachments->find('old-linked'));
		self::assertNotNull($attachments->find('remote-linked'));
		self::assertNotNull($attachments->find('fresh'));
		self::assertFalse($blobs->exists($oldOrphan->storageKey));
		self::assertTrue($blobs->exists($oldLinked->storageKey));
		self::assertTrue($blobs->exists($remoteLinked->storageKey));
		self::assertTrue($blobs->exists($fresh->storageKey));

		self::assertSame(2, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . NotificationSchema::TABLE,
		));
		self::assertNull($notifications->find('old-read'));
		self::assertNotNull($notifications->find('old-unread'));
		self::assertNotNull($notifications->find('fresh-read'));

		self::assertSame(1, (int) $database->fetchValue(
			'SELECT COUNT(*) FROM ' . RemoteThreadSchema::TABLE,
		));
		self::assertNull($threads->find('https://remote.example/notes/old'));
		self::assertNotNull($threads->find('https://remote.example/notes/fresh'));
	}

	private function database(): Connection
	{
		$database = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($database);
		PostPinSchema::create($database);
		MediaCollectionSchema::create($database);
		AttachmentSchema::create($database);
		PollSchema::create($database);
		BookmarkSchema::create($database);
		BoostSchema::create($database);
		FavouriteSchema::create($database);
		ReactionSchema::create($database);
		ScheduledPostSchema::create($database);
		FilterSchema::create($database);
		RecommendationSchema::create($database);
		StorySchema::create($database);
		RemotePollSchema::create($database);
		QuoteGrantSchema::create($database);
		NotificationSchema::create($database);
		NotificationPolicySchema::create($database);
		NotificationSenderSchema::create($database);
		ConversationMuteSchema::create($database);
		SearchIndexSchema::create($database);
		TrendSchema::create($database);
		FollowerSchema::create($database);
		FollowingSchema::create($database);
		AtprotoFollowSchema::create($database);
		AccountMoveSchema::create($database);
		RemotePostSchema::create($database);
		PendingRemoteUpdateSchema::create($database);
		RemoteThreadSchema::create($database);
		BlockSchema::create($database);
	\AlohaSocial\Core\Domain\Federation\InboundBlockSchema::create($database);
		MuteSchema::create($database);
		AccountListSchema::create($database);
		FollowedHashtagSchema::create($database);
		JobSchema::create($database);
		AccountDeletionSchema::create($database);
		ActivityLogSchema::create($database);
		AtprotoPublicationSchema::create($database);
		ReportSchema::create($database);
		ModerationActionSchema::create($database);
		HeldPostSchema::create($database);

		return $database;
	}

	/**
	 * The production wiring, not just the resolver unit: a host supplies a
	 * plain HttpActorResolver and the assembly's guard hands it the address it
	 * validated, so the document fetch connects to what was checked.
	 */
	public function testTheHostAssemblyPinsTheSigningActorFetchToTheValidatedAddress(): void
	{
		$database = $this->database();
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], json_encode([
			'id' => self::BOB,
			'type' => 'Person',
			'inbox' => self::BOB_INBOX,
			'publicKey' => [
				'id' => 'https://remote.example/users/bob#main-key',
				'owner' => self::BOB,
				'publicKeyPem' => self::$remoteKey->publicKeyPem(),
			],
		], JSON_THROW_ON_ERROR)));

		$app = $this->host($database, $http, new HttpActorResolver($http));

		$response = $app->handle($this->signedInbox([
			'id' => 'https://remote.example/activities/follow-pinned',
			'type' => 'Follow',
			'actor' => self::BOB,
			'object' => 'https://localhost:8080/@alice',
		]));

		self::assertSame(202, $response->status, $response->body);
		self::assertSame(['93.184.216.34'], $http->requests[0]['resolved_ips']);
		self::assertSame(HttpActorResolver::MAX_DOCUMENT_BYTES, $http->requests[0]['max_bytes']);
	}

	private function host(
		Connection $database,
		FakeHttpClient $http,
		?ActorResolver $actorResolver = null,
		bool $mastodon = false,
		bool $atproto = false,
		?AtprotoAccountResolver $atprotoAccounts = null,
		array $actors = ['alice'],
		?BlobStore $blobStore = null,
		?JobQueue $queue = null,
		?InboxResolver $inboxResolver = null,
		?AtprotoInteractionTargetPolicy $atprotoRemoteTargetPolicy = null,
	): Application {
		return Application::forHost(
			config: new ArrayConfig([
				'social' => [
					'instance.url' => 'https://localhost:8080',
					'instance.name' => 'Aloha Social',
					'mastodon.api' => $mastodon ? 'true' : 'false',
					'atproto.publish' => $atproto ? 'true' : 'false',
				],
			]),
			database: $database,
			httpClient: $http,
			signingKey: self::$key,
			localActors: new InMemoryActorDirectory($actors),
			queue: $queue ?? new PdoJobQueue($database, $this->clock, new RandomIdGenerator()),
			inboxResolver: $inboxResolver,
			activityLog: new PdoActivityLog($database),
			actorResolver: $actorResolver,
			atprotoAccounts: $atprotoAccounts,
			atprotoRemoteTargetPolicy: $atprotoRemoteTargetPolicy,
			blobStore: $blobStore,
			clock: $this->clock,
			hostResolver: new TestHostResolver([
				'remote.example' => ['93.184.216.34'],
				'localhost' => ['127.0.0.1'],
			]),
		);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function signedInbox(array $activity): Request
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);

		return new Request(
			method: 'POST',
			path: '/inbox',
			headers: HttpSignature::sign(
				self::$remoteKey,
				'https://remote.example/users/bob#main-key',
				'POST',
				'/inbox',
				'localhost',
				$body,
				$this->clock->now(),
			),
			body: $body,
		);
	}
}
