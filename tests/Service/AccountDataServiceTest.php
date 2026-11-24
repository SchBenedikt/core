<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Account\AccountDeletionSchema;
use AlohaSocial\Core\Domain\Account\PdoAccountDataRepository;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationSchema;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionSchema;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsSchema;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsFirehose;
use AlohaSocial\Core\Domain\Atproto\Pds\PdoPdsRepository;
use AlohaSocial\Core\Domain\Collection\MediaCollectionSchema;
use AlohaSocial\Core\Domain\Federation\AccountMoveSchema;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowerSchema;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\RemotePollSchema;
use AlohaSocial\Core\Domain\Filter\FilterSchema;
use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Media\AttachmentSchema;
use AlohaSocial\Core\Domain\Moderation\HeldPostSchema;
use AlohaSocial\Core\Domain\Moderation\ReportSchema;
use AlohaSocial\Core\Domain\Notification\ConversationMuteSchema;
use AlohaSocial\Core\Domain\Notification\NotificationPolicySchema;
use AlohaSocial\Core\Domain\Notification\NotificationSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSenderSchema;
use AlohaSocial\Core\Domain\Post\BookmarkSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PostPinSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Post\QuoteGrantSchema;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSchema;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostSchema;
use AlohaSocial\Core\Domain\Search\SearchIndexSchema;
use AlohaSocial\Core\Domain\Story\StorySchema;
use AlohaSocial\Core\Domain\Timeline\AccountListSchema;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagSchema;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Account\AccountDataService;
use AlohaSocial\Core\Service\Account\AccountErasureBlobRetryHandler;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

use function array_column;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The archive and erasure are checked against real SQL rows, not a hand-made
 * fixture object: every row is written into the same schemas the production
 * repositories use, and the service reads them back through PDO.
 */
final class AccountDataServiceTest extends TestCase
{
	private Connection $database;

	private InMemoryBlobStore $blobs;

	private AccountDataService $service;

	private PdoAccountDataRepository $repository;

	private InMemoryJobQueue $queue;

	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->database = new PdoConnection(new PDO('sqlite::memory:'));
		$this->createSchemas();
		$this->blobs = new InMemoryBlobStore();
		$this->clock = new FrozenClock(Time::parse('2026-11-16T12:00:00Z'));
		$this->repository = new PdoAccountDataRepository($this->database);
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));
		$this->service = new AccountDataService(
			$this->repository,
			$this->blobs,
			new ActivityFactory(new ArrayConfig([
				'social' => ['instance.url' => 'https://social.example'],
			])),
			$this->queue,
			$this->clock,
		);

		$this->seed();
	}

	public function testTheArchiveContainsEveryOwnedRowAndByteAndNoForeignRow(): void
	{
		$archive = $this->service->export('alice');

		self::assertSame('alohasocial-account-archive', $archive['format']);
		self::assertSame(1, $archive['version']);
		self::assertSame('alice', $archive['account']);
		self::assertSame('https://social.example/@alice', $archive['actor']);

		self::assertSame(['post-a'], array_column($archive['data']['social_post'], 'id'));
		self::assertSame(['alice', 'bob'], array_column($archive['data'][FollowerSchema::TABLE], 'local_user_id'));
		self::assertSame(['att-a'], array_column($archive['data']['social_post_attachment'], 'attachment_id'));
		self::assertSame(['post-b'], array_column($archive['data']['social_post_recipient'], 'post_id'));
		self::assertSame(['post-b'], array_column($archive['data']['social_favourite'], 'post_id'));
		self::assertSame(['reaction-a'], array_column($archive['data']['social_reaction'], 'id'));
		self::assertSame(['post-a'], array_column($archive['data']['social_poll'], 'post_id'));
		self::assertSame(['story-a'], array_column($archive['data']['social_story'], 'id'));
		self::assertSame(['story-a'], array_column($archive['data']['social_story_view'], 'story_id'));
		self::assertSame(['list-alice'], array_column($archive['data']['social_account_list'], 'id'));
		self::assertSame(['q-a'], array_column($archive['data']['social_quote_grant'], 'id'));
		self::assertSame(['move-a'], array_column($archive['data']['social_account_move'], 'event_id'));
		self::assertSame(['coll-alice'], array_column($archive['data']['social_media_collection'], 'id'));
		self::assertSame(['pub-a'], array_column($archive['data']['social_atproto_publication'], 'post_id'));
		self::assertSame(['interaction-a'], array_column($archive['data']['social_atproto_interaction'], 'post_id'));
		self::assertSame(
			['did:plc:followed-by-alice'],
			array_column($archive['data']['social_atproto_follow'], 'subject_did'),
		);

		self::assertSame(['alice'], array_column($archive['data']['social_attachment'], 'owner_id'));
		self::assertSame(['did:plc:alice'], array_column($archive['data']['social_pds_identity'], 'did'));
		self::assertSame(['key-a'], array_column($archive['data']['social_pds_key'], 'id'));
		self::assertArrayNotHasKey('private_key_sealed', $archive['data']['social_pds_key'][0]);
		self::assertSame(['record-a'], array_column($archive['data']['social_pds_record'], 'rkey'));
		self::assertSame(['event-a'], array_column($archive['data']['social_pds_event'], 'commit_cid'));

		self::assertSame(base64_encode('alice-media'), $archive['blobs']['media/att-a/original.jpg']);
		self::assertSame(base64_encode('alice-thumb'), $archive['blobs']['media/att-a/thumbnail.jpg']);
		self::assertArrayHasKey(
			'pds/blobs/' . hash('sha256', 'did:plc:alice') . '/blob-a.bin',
			$archive['blobs'],
		);
		self::assertArrayNotHasKey('media/att-b/original.jpg', $archive['blobs']);
		self::assertSame([], $archive['missing_blobs']);
		self::assertArrayHasKey('social_job', $archive['not_attributable']);

		// Identical stored data must produce an identical archive byte-for-byte
		// when the caller encodes it; no wall-clock field is included.
		self::assertSame(
			json_encode($archive, JSON_THROW_ON_ERROR),
			json_encode($this->service->export('alice'), JSON_THROW_ON_ERROR),
		);
	}

	public function testErasureRemovesOwnedRowsAndBlobsButLeavesAnotherAccountIntact(): void
	{
		$report = $this->service->erase('alice');

		self::assertTrue($report->complete());
		self::assertSame(1, $report->counts[PostSchema::TABLE]);
		self::assertSame(2, $report->counts[FavouriteSchema::TABLE]);
		self::assertSame(2, $report->counts[ReactionSchema::TABLE]);
		self::assertSame(2, $report->counts[StorySchema::VIEW_TABLE]);
		self::assertSame(1, $report->counts[PdsSchema::IDENTITY_TABLE]);
		self::assertGreaterThanOrEqual(3, $report->blobsDeleted);

		self::assertSame(['post-b'], array_column($this->rows(PostSchema::TABLE), 'id'));
		self::assertSame(['post-b'], array_column($this->rows(PostSchema::ATTACHMENT_TABLE), 'post_id'));
		self::assertSame([], $this->rows(PostSchema::RECIPIENT_TABLE));
		self::assertSame(['post-b'], array_column($this->rows(FavouriteSchema::TABLE), 'post_id'));
		self::assertSame(['post-b'], array_column($this->rows(BookmarkSchema::TABLE), 'post_id'));
		self::assertSame(['boost-b2'], array_column($this->rows(BoostSchema::TABLE), 'id'));
		self::assertSame(['reaction-b'], array_column($this->rows(ReactionSchema::TABLE), 'id'));

		self::assertSame(['story-b'], array_column($this->rows(StorySchema::TABLE), 'id'));
		self::assertSame(['story-b'], array_column($this->rows(StorySchema::VIEW_TABLE), 'story_id'));
		self::assertSame(['story-b'], array_column($this->rows(StorySchema::REACTION_TABLE), 'story_id'));
		self::assertSame(['reply-b2'], array_column($this->rows(StorySchema::REPLY_TABLE), 'id'));

		foreach ([
			PostSchema::HASHTAG_TABLE,
			PostSchema::REVISION_TABLE,
			PollSchema::TABLE,
			PollSchema::OPTION_TABLE,
			PollSchema::VOTE_TABLE,
			PostSchema::RECIPIENT_TABLE,
			AccountListSchema::MEMBER_TABLE,
		] as $emptyTable) {
			self::assertSame([], $this->rows($emptyTable), $emptyTable . ' must be empty after erasure.');
		}

		self::assertSame(['list-bob'], array_column($this->rows(AccountListSchema::TABLE), 'id'));
		self::assertSame([], $this->rows(AccountListSchema::MEMBER_TABLE));
		self::assertSame(['notif-bob'], array_column($this->rows(NotificationSchema::TABLE), 'id'));
		self::assertSame(['q-b'], array_column($this->rows(QuoteGrantSchema::TABLE), 'id'));
		self::assertSame(['move-b'], array_column($this->rows(AccountMoveSchema::TABLE), 'event_id'));
		self::assertSame(['coll-bob'], array_column($this->rows(MediaCollectionSchema::TABLE), 'id'));
		self::assertSame(['coll-bob'], array_column($this->rows(MediaCollectionSchema::ITEM_TABLE), 'collection_id'));
		self::assertSame(['pub-b'], array_column($this->rows(AtprotoPublicationSchema::TABLE), 'post_id'));
		self::assertSame(['interaction-b'], array_column($this->rows(AtprotoInteractionSchema::TABLE), 'post_id'));
		self::assertSame(
			['did:plc:followed-by-bob'],
			array_column($this->rows(AtprotoFollowSchema::TABLE), 'subject_did'),
		);
		self::assertSame(['search-bob'], array_column($this->rows(SearchIndexSchema::TABLE), 'id'));
		self::assertSame(['sched-bob'], array_column($this->rows(ScheduledPostSchema::TABLE), 'id'));

		self::assertSame(['att-b'], array_column($this->rows(AttachmentSchema::TABLE), 'id'));
		self::assertTrue($this->blobs->exists('media/att-b/original.jpg'));
		self::assertFalse($this->blobs->exists('media/att-a/original.jpg'));
		self::assertFalse($this->blobs->exists('media/att-a/thumbnail.jpg'));
		self::assertFalse($this->blobs->exists(
			'pds/blobs/' . hash('sha256', 'did:plc:alice') . '/blob-a.bin',
		));
		self::assertTrue($this->blobs->exists(
			'pds/blobs/' . hash('sha256', 'did:plc:bob') . '/blob-b.bin',
		));

		self::assertSame(['did:plc:bob'], array_column($this->rows(PdsSchema::IDENTITY_TABLE), 'did'));
		self::assertSame(['key-b'], array_column($this->rows(PdsSchema::KEY_TABLE), 'id'));
		self::assertSame(['record-b'], array_column($this->rows(PdsSchema::RECORD_TABLE), 'rkey'));
		self::assertSame(['event-b'], array_column(
			array_values(array_filter(
				$this->rows(PdsSchema::EVENT_TABLE),
				static fn (array $row): bool => $row['commit_cid'] !== '',
			)),
			'commit_cid',
		));

		// A second call returns the stored result, not a second (smaller) pass.
		$again = $this->service->erase('alice');
		self::assertTrue($again->complete());
		self::assertSame('erased', $again->state);
		self::assertSame($report->blobsDeleted, $again->blobsDeleted);
		self::assertSame($report->counts, $again->counts);
		self::assertSame($report->toArray(), $again->toArray());
	}

	public function testADeleteCallDuringAnotherOwnersRunReturnsTheCurrentStatus(): void
	{
		$this->repository->beginDeletion('alice', $this->clock->now());
		self::assertTrue($this->repository->claimDeletion('alice', $this->clock->now()));

		$inProgress = $this->service->erase('alice');

		self::assertSame('erasing', $inProgress->state);
		self::assertTrue($inProgress->inProgress());
		self::assertSame(403, $this->repository->accountWriteStatus('alice'));

		$this->repository->finishDeletion(
			'alice',
			[PostSchema::TABLE => 3],
			2,
			[],
			$this->clock->now(),
		);

		$again = $this->service->erase('alice');
		self::assertSame('erased', $again->state);
		self::assertSame([PostSchema::TABLE => 3], $again->counts);
		self::assertSame(2, $again->blobsDeleted);
	}

	public function testRepeatedEraseRetriesOnlyPreviouslyFailedBlobDeletes(): void
	{
		$store = new FailOnceBlobStore($this->blobs, 'media/att-a/original.jpg');
		$service = new AccountDataService(
			$this->repository,
			$store,
			new ActivityFactory(new ArrayConfig([
				'social' => ['instance.url' => 'https://social.example'],
			])),
			$this->queue,
			$this->clock,
		);

		$first = $service->erase('alice');
		self::assertSame(['media/att-a/original.jpg'], $first->blobFailures);
		self::assertGreaterThanOrEqual(2, $first->blobsDeleted);
		self::assertSame('erased', $first->state);
		$jobs = $this->queue->reserve(100, $this->clock->now()->modify('+2 minutes'));
		$retryJob = null;
		foreach ($jobs as $job) {
			if ($job->type === JobType::ACCOUNT_ERASURE_BLOB_RETRY) {
				$retryJob = $job;
				break;
			}
		}
		self::assertNotNull($retryJob, 'A durable retry should be queued after failed object deletion.');
		(new AccountErasureBlobRetryHandler($service))->handle($retryJob);
		$this->queue->complete($retryJob);

		$retried = $service->erase('alice');
		self::assertTrue($retried->complete());
		self::assertSame([], $retried->blobFailures);
		self::assertSame($first->blobsDeleted + 1, $retried->blobsDeleted);
		self::assertFalse($this->blobs->exists('media/att-a/original.jpg'));
		self::assertFalse($this->blobs->exists('media/att-a/thumbnail.jpg'));
		self::assertSame($retried->toArray(), $service->erase('alice')->toArray());
	}

	public function testStaleBlobCleanupRetryCannotOverwriteNewerResult(): void
	{
		$path = 'media/att-a/original.jpg';
		$this->repository->beginDeletion('alice', $this->clock->now());
		self::assertTrue($this->repository->claimDeletion('alice', $this->clock->now()));
		$this->repository->finishDeletion('alice', [], 1, [$path], $this->clock->now());

		$updated = $this->repository->updateDeletionBlobFailures(
			'alice',
			[$path],
			[],
			1,
			$this->clock->now(),
		);
		self::assertNotNull($updated);
		self::assertSame([], $updated->blobFailures);
		self::assertSame(2, $updated->blobsDeleted);

		$stale = $this->repository->updateDeletionBlobFailures(
			'alice',
			[$path],
			[$path],
			0,
			$this->clock->now(),
		);
		self::assertNull($stale);
		self::assertSame([], $this->repository->deletion('alice')?->blobFailures);
		self::assertSame(2, $this->repository->deletion('alice')?->blobsDeleted);
	}

	public function testAccountErasureKeepsASequencedPdsDeletionEvent(): void
	{
		$this->database->execute(
			'DELETE FROM ' . PdsSchema::SEQUENCE_TABLE . ' WHERE name = :name',
			['name' => 'firehose'],
		);

		$this->service->erase('alice');

		$events = (new PdsFirehose(new PdoPdsRepository($this->database)))->after(2);
		self::assertCount(1, $events);
		self::assertSame([
			'$type' => '#account',
			'seq' => 3,
			'did' => 'did:plc:alice',
			'time' => '2026-11-16T12:00:00Z',
			'active' => false,
			'status' => 'deleted',
		], $events[0]->toArray());
		self::assertSame(['did:plc:bob'], array_column($this->rows(PdsSchema::IDENTITY_TABLE), 'did'));
		self::assertSame(['event-b'], array_column(
			array_values(array_filter(
				$this->rows(PdsSchema::EVENT_TABLE),
				static fn (array $row): bool => $row['commit_cid'] !== '',
			)),
			'commit_cid',
		));
	}

	public function testDeletionFreezesTheAccountAndCancelsItsQueuedJobsBeforeErasing(): void
	{
		$this->queue->push('deliver', [], null, JobSubject::account('alice'));
		$this->queue->push('deliver', [], null, JobSubject::account('bob'));
		$this->queue->push('cleanup', []);

		$report = $this->service->erase('alice');

		self::assertTrue($report->complete());
		self::assertSame(3, $this->queue->pendingCount());
		self::assertSame(410, $this->repository->accountWriteStatus('alice'));
		self::assertNull($this->repository->accountWriteStatus('bob'));
		self::assertSame('erased', $this->service->deletionStatus('alice')->state);
	}

	public function testDeletionQueuesActorDeleteAndFollowUndosBeforeErasingTheGraph(): void
	{
		// Isolate the remote graph fixtures while keeping the local outgoing
		// edge to Bob, which must be removed with Alice's actor identity.
		$this->database->execute('DELETE FROM ' . FollowerSchema::TABLE . ' WHERE local_user_id = :user', ['user' => 'alice']);
		$this->database->execute('DELETE FROM ' . FollowingSchema::TABLE . ' WHERE local_user_id = :user', ['user' => 'alice']);
		$this->insert(FollowerSchema::TABLE, [
			'id' => 'follower-a',
			'local_user_id' => 'alice',
			'actor_id' => 'https://peer.example/@a',
			'inbox_url' => 'https://peer.example/inbox/a',
			'shared_inbox_url' => 'https://peer.example/inbox/shared',
			'followed_at' => '2026-11-16T10:00:00Z',
			'is_local' => 0,
			'state' => 'accepted',
			'follow_activity' => null,
		]);
		$this->insert(FollowerSchema::TABLE, [
			'id' => 'follower-b',
			'local_user_id' => 'alice',
			'actor_id' => 'https://peer.example/@second',
			'inbox_url' => 'https://peer.example/inbox/b',
			'shared_inbox_url' => 'https://peer.example/inbox/shared',
			'followed_at' => '2026-11-16T10:01:00Z',
			'is_local' => 0,
			'state' => 'accepted',
			'follow_activity' => null,
		]);
		$this->insert(FollowerSchema::TABLE, [
			'id' => 'follower-pending',
			'local_user_id' => 'alice',
			'actor_id' => 'https://pending.example/@a',
			'inbox_url' => 'https://pending.example/inbox',
			'shared_inbox_url' => null,
			'followed_at' => '2026-11-16T10:02:00Z',
			'is_local' => 0,
			'state' => 'pending',
			'follow_activity' => null,
		]);
		$this->insert(FollowerSchema::TABLE, [
			'id' => 'follower-local',
			'local_user_id' => 'alice',
			'actor_id' => 'https://social.example/@charlie',
			'inbox_url' => 'https://social.example/inbox/shared',
			'shared_inbox_url' => null,
			'followed_at' => '2026-11-16T10:03:00Z',
			'is_local' => 1,
			'state' => 'accepted',
			'follow_activity' => null,
		]);
		$this->insert(FollowingSchema::TABLE, [
			'id' => 'follow-accepted',
			'local_user_id' => 'alice',
			'actor_id' => 'https://remote.example/@followed',
			'inbox_url' => 'https://remote.example/inbox',
			'followed_at' => '2026-11-16T10:04:00Z',
			'state' => 'accepted',
			'follow_activity_id' => 'https://social.example/api/v1/following/follow-accepted/follow',
		]);
		$this->insert(FollowingSchema::TABLE, [
			'id' => 'follow-pending',
			'local_user_id' => 'alice',
			'actor_id' => 'https://other.example/@pending',
			'inbox_url' => 'https://other.example/inbox',
			'followed_at' => '2026-11-16T10:05:00Z',
			'state' => 'pending',
			'follow_activity_id' => null,
		]);

		$this->queue->push('publish', [], null, JobSubject::account('alice'));
		$this->queue->push('publish', [], null, JobSubject::account('bob'));
		$report = $this->service->erase('alice');
		$jobs = $this->queue->reserve(10, $this->clock->now());
		$deliveries = array_values(array_filter(
			$jobs,
			static fn ($job): bool => $job->type === JobType::FEDERATION_DELIVER,
		));

		self::assertTrue($report->complete());
		self::assertCount(3, $deliveries, 'One deduplicated Delete and two outgoing Follow undos are queued.');
		foreach ($deliveries as $job) {
			self::assertNull($job->subject, 'Deletion notifications must remain runnable after account jobs are blocked.');
		}

		$delete = array_values(array_filter(
			$deliveries,
			static fn ($job): bool => ($job->payload['activity']['type'] ?? null) === 'Delete',
		));
		self::assertCount(1, $delete);
		self::assertContains('https://peer.example/inbox/shared', array_column(
			array_map(static fn ($job): array => $job->payload, $delete),
			'inbox',
		));
		self::assertSame('https://social.example/@alice#delete', $delete[0]->payload['activity']['id']);
		self::assertSame('https://social.example/@alice', $delete[0]->payload['activity']['object']);

		$undos = array_values(array_filter(
			$deliveries,
			static fn ($job): bool => ($job->payload['activity']['type'] ?? null) === 'Undo',
		));
		self::assertCount(2, $undos);
		self::assertSame(
			['https://remote.example/inbox', 'https://other.example/inbox'],
			array_column(array_map(static fn ($job): array => $job->payload, $undos), 'inbox'),
		);
		self::assertSame(1, count(array_filter($jobs, static fn ($job): bool => $job->subject?->id === 'bob')));
		self::assertSame(0, (int) $this->database->fetchValue(
			"SELECT COUNT(*) FROM " . FollowerSchema::TABLE . " WHERE actor_id = 'https://social.example/@alice'",
		));
	}

	public function testASweepRemovesALateWriteThatCommittedAfterTheFreeze(): void
	{
		$this->repository->beginDeletion('alice', $this->clock->now());
		$this->insert(PostSchema::TABLE, [
			'id' => 'post-late',
			'author_id' => 'alice',
			'content' => 'late write',
			'visibility' => 'public',
			'published_at' => '2026-11-16T12:00:01Z',
		]);

		$this->service->sweep('alice');

		self::assertNotContains('post-late', array_column($this->rows(PostSchema::TABLE), 'id'));
		self::assertContains('post-b', array_column($this->rows(PostSchema::TABLE), 'id'));
	}

	public function testAFrozenAccountAlreadyRejectsWritesBeforeRowsAreRemoved(): void
	{
		$this->repository->beginDeletion('alice', $this->clock->now());

		self::assertSame(403, $this->repository->accountWriteStatus('alice'));
		self::assertContains('post-a', array_column($this->rows(PostSchema::TABLE), 'id'));

		$report = $this->service->erase('alice');

		self::assertSame('erased', $report->state);
		self::assertSame(410, $this->repository->accountWriteStatus('alice'));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $table): array
	{
		return $this->database->fetchAll('SELECT * FROM ' . $table . ' ORDER BY 1');
	}

	private function createSchemas(): void
	{
		PostSchema::create($this->database);
		PostPinSchema::create($this->database);
		MediaCollectionSchema::create($this->database);
		AttachmentSchema::create($this->database);
		PollSchema::create($this->database);
		BookmarkSchema::create($this->database);
		BoostSchema::create($this->database);
		FavouriteSchema::create($this->database);
		ReactionSchema::create($this->database);
		ScheduledPostSchema::create($this->database);
		FilterSchema::create($this->database);
		RecommendationSchema::create($this->database);
		StorySchema::create($this->database);
		RemotePollSchema::create($this->database);
		QuoteGrantSchema::create($this->database);
		NotificationSchema::create($this->database);
		NotificationPolicySchema::create($this->database);
		NotificationSenderSchema::create($this->database);
		ConversationMuteSchema::create($this->database);
		SearchIndexSchema::create($this->database);
		FollowerSchema::create($this->database);
		FollowingSchema::create($this->database);
		AccountMoveSchema::create($this->database);
		BlockSchema::create($this->database);
		MuteSchema::create($this->database);
		AccountListSchema::create($this->database);
		FollowedHashtagSchema::create($this->database);
		AtprotoPublicationSchema::create($this->database);
		AtprotoInteractionSchema::create($this->database);
		AtprotoFollowSchema::create($this->database);
		ReportSchema::create($this->database);
		HeldPostSchema::create($this->database);
		PdsSchema::create($this->database);
		AccountDeletionSchema::create($this->database);
	}

	private function seed(): void
	{
		$this->insert(PostSchema::TABLE, [
			'id' => 'post-a',
			'author_id' => 'alice',
			'content' => 'alice post',
			'visibility' => 'public',
			'published_at' => '2026-11-16T10:00:00Z',
		]);
		$this->insert(PostSchema::TABLE, [
			'id' => 'post-b',
			'author_id' => 'bob',
			'content' => 'bob post',
			'visibility' => 'direct',
			'published_at' => '2026-11-16T10:01:00Z',
		]);
		$this->insert(PostSchema::ATTACHMENT_TABLE, [
			'post_id' => 'post-a', 'attachment_id' => 'att-a', 'position' => 0,
		]);
		$this->insert(PostSchema::ATTACHMENT_TABLE, [
			'post_id' => 'post-b', 'attachment_id' => 'att-b', 'position' => 0,
		]);
		$this->insert(PostSchema::RECIPIENT_TABLE, [
			'post_id' => 'post-b', 'recipient_id' => 'alice', 'position' => 0,
		]);
		$this->insert(PostSchema::HASHTAG_TABLE, [
			'post_id' => 'post-a', 'hashtag' => 'alice', 'position' => 0,
		]);
		$this->insert(PostSchema::REVISION_TABLE, [
			'post_id' => 'post-a',
			'revision' => 1,
			'content' => 'old alice post',
			'content_warning' => null,
			'visibility' => 'public',
			'attachment_ids' => '["att-a"]',
			'edited_at' => '2026-11-16T10:02:00Z',
		]);

		$this->insert(PollSchema::TABLE, [
			'post_id' => 'post-a',
			'multiple' => 0,
			'hide_totals' => 0,
			'expires_at' => '2026-12-01T00:00:00Z',
			'votes_count' => 2,
			'voters_count' => 2,
		]);
		$this->insert(PollSchema::OPTION_TABLE, [
			'post_id' => 'post-a', 'position' => 0, 'title' => 'yes', 'votes_count' => 1,
		]);
		$this->insert(PollSchema::OPTION_TABLE, [
			'post_id' => 'post-a', 'position' => 1, 'title' => 'no', 'votes_count' => 1,
		]);
		$this->insert(PollSchema::VOTE_TABLE, [
			'post_id' => 'post-a', 'user_id' => 'alice', 'positions' => '0', 'created_at' => '2026-11-16T10:03:00Z',
		]);
		$this->insert(PollSchema::VOTE_TABLE, [
			'post_id' => 'post-a', 'user_id' => 'bob', 'positions' => '1', 'created_at' => '2026-11-16T10:04:00Z',
		]);

		$this->insert(PostPinSchema::TABLE, [
			'owner_id' => 'alice', 'post_id' => 'post-a', 'position' => 0, 'pinned_at' => '2026-11-16T10:05:00Z',
		]);
		$this->insert(PostPinSchema::TABLE, [
			'owner_id' => 'bob', 'post_id' => 'post-b', 'position' => 0, 'pinned_at' => '2026-11-16T10:06:00Z',
		]);

		$this->insert(QuoteGrantSchema::TABLE, [
			'id' => 'q-a',
			'quoted_object_id' => 'https://social.example/posts/post-b',
			'interacting_object_id' => 'https://social.example/posts/post-a',
			'actor_id' => 'https://social.example/@alice',
			'quoted_actor_id' => 'https://social.example/@bob',
			'state' => 'accepted',
			'created_at' => '2026-11-16T10:07:00Z',
			'updated_at' => '2026-11-16T10:07:00Z',
		]);
		$this->insert(QuoteGrantSchema::TABLE, [
			'id' => 'q-b',
			'quoted_object_id' => 'https://social.example/posts/post-a',
			'interacting_object_id' => 'https://social.example/posts/post-b',
			'actor_id' => 'https://social.example/@bob',
			'quoted_actor_id' => 'https://social.example/@alice',
			'state' => 'accepted',
			'created_at' => '2026-11-16T10:08:00Z',
			'updated_at' => '2026-11-16T10:08:00Z',
		]);

		foreach ([
			[FavouriteSchema::TABLE, 'alice', 'post-b'],
			[FavouriteSchema::TABLE, 'bob', 'post-a'],
			[FavouriteSchema::TABLE, 'bob', 'post-b'],
			[BookmarkSchema::TABLE, 'alice', 'post-b'],
			[BookmarkSchema::TABLE, 'bob', 'post-a'],
			[BookmarkSchema::TABLE, 'bob', 'post-b'],
		] as [$table, $user, $post]) {
			$this->insert($table, [
				'user_id' => $user,
				'post_id' => $post,
				'created_at' => '2026-11-16T10:09:00Z',
			]);
		}

		$this->insert(BoostSchema::TABLE, [
			'id' => 'boost-a', 'user_id' => 'alice', 'post_id' => 'post-b', 'created_at' => '2026-11-16T10:10:00Z',
		]);
		$this->insert(BoostSchema::TABLE, [
			'id' => 'boost-b1', 'user_id' => 'bob', 'post_id' => 'post-a', 'created_at' => '2026-11-16T10:11:00Z',
		]);
		$this->insert(BoostSchema::TABLE, [
			'id' => 'boost-b2', 'user_id' => 'bob', 'post_id' => 'post-b', 'created_at' => '2026-11-16T10:12:00Z',
		]);

		$this->insert(ReactionSchema::TABLE, [
			'id' => 'reaction-a',
			'direction' => 'out',
			'subject_id' => 'alice',
			'object_id' => 'https://remote.example/notes/1',
			'local_post_id' => null,
			'kind' => 'like',
			'emoji' => '',
			'icon_url' => null,
			'activity_id' => 'https://social.example/activities/reaction-a',
			'created_at' => '2026-11-16T10:13:00Z',
		]);
		$this->insert(ReactionSchema::TABLE, [
			'id' => 'reaction-b',
			'direction' => 'out',
			'subject_id' => 'bob',
			'object_id' => 'https://remote.example/notes/2',
			'local_post_id' => null,
			'kind' => 'like',
			'emoji' => '',
			'icon_url' => null,
			'activity_id' => 'https://social.example/activities/reaction-b',
			'created_at' => '2026-11-16T10:14:00Z',
		]);
		$this->insert(ReactionSchema::TABLE, [
			'id' => 'reaction-in-b',
			'direction' => 'in',
			'subject_id' => 'https://remote.example/users/bob',
			'object_id' => 'https://social.example/posts/post-a',
			'local_post_id' => 'post-a',
			'kind' => 'like',
			'emoji' => '',
			'icon_url' => null,
			'activity_id' => 'https://remote.example/activities/reaction-in-b',
			'created_at' => '2026-11-16T10:15:00Z',
		]);

		$this->insert(RemotePollSchema::VOTE_TABLE, [
			'object_id' => 'https://remote.example/polls/1',
			'user_id' => 'alice',
			'positions' => '0',
			'created_at' => '2026-11-16T10:16:00Z',
			'queued' => 0,
		]);
		$this->insert(RemotePollSchema::VOTE_TABLE, [
			'object_id' => 'https://remote.example/polls/2',
			'user_id' => 'bob',
			'positions' => '0',
			'created_at' => '2026-11-16T10:17:00Z',
			'queued' => 0,
		]);

		$this->insert(StorySchema::TABLE, [
			'id' => 'story-a',
			'owner_id' => 'alice',
			'is_local' => 1,
			'object_id' => 'https://social.example/stories/story-a',
			'caption' => 'alice story',
			'attachment_id' => 'att-a',
			'remote_media_url' => null,
			'created_at' => '2026-11-16T10:18:00Z',
			'expires_at' => '2026-11-17T10:18:00Z',
			'deleted_at' => null,
		]);
		$this->insert(StorySchema::TABLE, [
			'id' => 'story-b',
			'owner_id' => 'bob',
			'is_local' => 1,
			'object_id' => 'https://social.example/stories/story-b',
			'caption' => 'bob story',
			'attachment_id' => 'att-b',
			'remote_media_url' => null,
			'created_at' => '2026-11-16T10:19:00Z',
			'expires_at' => '2026-11-17T10:19:00Z',
			'deleted_at' => null,
		]);
		$this->insert(StorySchema::VIEW_TABLE, [
			'story_id' => 'story-a', 'viewer_id' => 'alice', 'viewed_at' => '2026-11-16T10:20:00Z',
		]);
		$this->insert(StorySchema::VIEW_TABLE, [
			'story_id' => 'story-a', 'viewer_id' => 'bob', 'viewed_at' => '2026-11-16T10:21:00Z',
		]);
		$this->insert(StorySchema::VIEW_TABLE, [
			'story_id' => 'story-b', 'viewer_id' => 'bob', 'viewed_at' => '2026-11-16T10:22:00Z',
		]);
		$this->insert(StorySchema::REACTION_TABLE, [
			'story_id' => 'story-a', 'user_id' => 'alice', 'emoji' => '❤', 'created_at' => '2026-11-16T10:23:00Z',
		]);
		$this->insert(StorySchema::REACTION_TABLE, [
			'story_id' => 'story-a', 'user_id' => 'bob', 'emoji' => '❤', 'created_at' => '2026-11-16T10:24:00Z',
		]);
		$this->insert(StorySchema::REACTION_TABLE, [
			'story_id' => 'story-b', 'user_id' => 'bob', 'emoji' => '❤', 'created_at' => '2026-11-16T10:25:00Z',
		]);
		$this->insert(StorySchema::REPLY_TABLE, [
			'id' => 'reply-a', 'story_id' => 'story-a', 'author_id' => 'alice', 'content' => 'alice reply',
			'created_at' => '2026-11-16T10:26:00Z',
		]);
		$this->insert(StorySchema::REPLY_TABLE, [
			'id' => 'reply-b1', 'story_id' => 'story-a', 'author_id' => 'bob', 'content' => 'bob reply',
			'created_at' => '2026-11-16T10:27:00Z',
		]);
		$this->insert(StorySchema::REPLY_TABLE, [
			'id' => 'reply-b2', 'story_id' => 'story-b', 'author_id' => 'bob', 'content' => 'bob reply 2',
			'created_at' => '2026-11-16T10:28:00Z',
		]);
		$this->insert(StorySchema::TOMBSTONE_TABLE, [
			'object_id' => 'https://social.example/stories/story-a-old',
			'owner_id' => 'alice',
			'deleted_at' => '2026-11-16T10:29:00Z',
		]);
		$this->insert(StorySchema::TOMBSTONE_TABLE, [
			'object_id' => 'https://social.example/stories/story-b-old',
			'owner_id' => 'bob',
			'deleted_at' => '2026-11-16T10:30:00Z',
		]);

		foreach (['alice', 'bob'] as $user) {
			$other = $user === 'alice' ? 'bob' : 'alice';
			$this->insert(FollowingSchema::TABLE, [
				'id' => 'follow-' . $user,
				'local_user_id' => $user,
				'actor_id' => 'https://peer.example/@' . $other,
				'inbox_url' => 'https://peer.example/@' . $other . '/inbox',
				'followed_at' => '2026-11-16T10:31:00Z',
			]);
			$this->insert(FollowerSchema::TABLE, [
				'id' => 'follower-' . $user,
				'local_user_id' => $user,
				'actor_id' => 'https://social.example/@' . $other,
				'inbox_url' => 'https://social.example/@' . $other . '/inbox',
				'followed_at' => '2026-11-16T10:32:00Z',
				'is_local' => 1,
			]);
			$this->insert(BlockSchema::TABLE, [
				'id' => 'block-' . $user,
				'local_user_id' => $user,
				'actor_id' => 'https://social.example/@' . $other,
				'blocked_at' => '2026-11-16T10:33:00Z',
			]);
			$this->insert(MuteSchema::TABLE, [
				'user_id' => $user,
				'actor_id' => 'https://social.example/@' . $other,
				'notifications' => 1,
				'created_at' => '2026-11-16T10:34:00Z',
			]);
			$this->insert(AccountListSchema::TABLE, [
				'id' => 'list-' . $user,
				'user_id' => $user,
				'title' => $user . ' list',
				'replies_policy' => 'all',
				'exclusive' => 0,
				'created_at' => '2026-11-16T10:35:00Z',
				'updated_at' => '2026-11-16T10:35:00Z',
			]);
			$this->insert(FollowedHashtagSchema::TABLE, [
				'user_id' => $user,
				'hashtag' => $user,
				'created_at' => '2026-11-16T10:36:00Z',
			]);
			$this->insert(FilterSchema::TABLE, [
				'id' => 'filter-' . $user,
				'user_id' => $user,
				'name' => $user . ' filter',
				'action' => 'hide',
				'contexts' => '["home"]',
				'keywords' => '["spoiler"]',
				'created_at' => '2026-11-16T10:37:00Z',
				'expires_at' => null,
			]);
			$this->insert(NotificationSchema::TABLE, [
				'id' => 'notif-' . $user,
				'recipient_id' => $user,
				'actor_id' => 'https://social.example/@' . $other,
				'type' => 'follow',
				'post_id' => null,
				'created_at' => '2026-11-16T10:38:00Z',
				'read_at' => null,
			]);
			$this->insert(NotificationSenderSchema::TABLE, [
				'id' => 'sender-' . $user,
				'recipient_id' => $user,
				'actor_id' => 'https://social.example/@' . $other,
				'status' => 'accepted',
				'category' => 'follow',
				'created_at' => '2026-11-16T10:39:00Z',
			]);
			$this->insert(NotificationPolicySchema::TABLE, [
				'user_id' => $user,
				'actions' => '{}',
				'new_account_days' => 0,
				'updated_at' => '2026-11-16T10:40:00Z',
			]);
			$this->insert(ConversationMuteSchema::TABLE, [
				'id' => 'conversation-mute-' . $user,
				'user_id' => $user,
				'conversation_id' => 'conversation-' . $user,
				'created_at' => '2026-11-16T10:41:00Z',
			]);
			$this->insert(ReportSchema::TABLE, [
				'id' => 'report-' . $user,
				'reporter_id' => $user,
				'target_type' => 'post',
				'target_id' => 'post-' . $user,
				'reason' => 'spam',
				'comment' => null,
				'evidence' => '[]',
				'status' => 'open',
				'created_at' => '2026-11-16T10:42:00Z',
				'updated_at' => '2026-11-16T10:42:00Z',
			]);
			$this->insert(HeldPostSchema::TABLE, [
				'id' => 'held-' . $user,
				'author_id' => $user,
				'draft' => '{}',
				'reason' => 'review',
				'status' => 'pending',
				'post_id' => null,
				'created_at' => '2026-11-16T10:43:00Z',
			]);
			$this->insert(RecommendationSchema::PREFERENCE_TABLE, [
				'user_id' => $user,
				'enabled' => 1,
				'behavioral_consent' => 0,
				'interests_json' => '[]',
				'languages_json' => '[]',
				'updated_at' => '2026-11-16T10:44:00Z',
			]);
			$this->insert(RecommendationSchema::FEEDBACK_TABLE, [
				'user_id' => $user,
				'candidate_id' => 'candidate-' . $user,
				'action' => 'more',
				'created_at' => '2026-11-16T10:45:00Z',
			]);
			$this->insert(RecommendationSchema::SIGNAL_TABLE, [
				'id' => 'signal-' . $user,
				'user_id' => $user,
				'kind' => 'profile',
				'candidate_id' => 'candidate-' . $user,
				'created_at' => '2026-11-16T10:46:00Z',
			]);
			$this->insert(SearchIndexSchema::TABLE, [
				'id' => 'search-' . $user,
				'kind' => 'post',
				'source' => 'local',
				'author_id' => $user,
				'content' => $user . ' search',
				'hashtags' => '[]',
				'aliases' => '[]',
				'visibility' => 'public',
				'published_at' => '2026-11-16T10:47:00Z',
				'cached_at' => '2026-11-16T10:47:00Z',
			]);
			$this->insert(ScheduledPostSchema::TABLE, [
				'id' => 'sched-' . $user,
				'author_id' => $user,
				'post_id' => 'scheduled-post-' . $user,
				'content' => $user . ' scheduled',
				'content_warning' => null,
				'visibility' => 'public',
				'attachment_ids' => '[]',
				'recipient_ids' => '[]',
				'in_reply_to_id' => null,
				'scheduled_at' => '2026-12-01T10:00:00Z',
				'created_at' => '2026-11-16T10:48:00Z',
				'state' => 'pending',
			]);
			$this->insert(MediaCollectionSchema::TABLE, [
				'id' => 'coll-' . $user,
				'owner_id' => $user,
				'title' => $user . ' collection',
				'description' => '',
				'audience' => 'private',
				'created_at' => '2026-11-16T10:49:00Z',
				'updated_at' => '2026-11-16T10:49:00Z',
			]);
			$this->insert(MediaCollectionSchema::ITEM_TABLE, [
				'collection_id' => 'coll-' . $user,
				'post_id' => 'post-' . $user[0],
				'position' => 0,
				'added_at' => '2026-11-16T10:50:00Z',
			]);
			$this->insert(AtprotoPublicationSchema::TABLE, [
				'local_user_id' => $user,
				'account_did' => 'did:plc:' . $user,
				'post_id' => 'pub-' . $user[0],
				'rkey' => 'rkey-' . $user,
				'uri' => 'at://did:plc:' . $user . '/app.bsky.feed.post/rkey-' . $user,
				'published_at' => '2026-11-16T10:51:00Z',
			]);
			$this->insert(AtprotoFollowSchema::TABLE, [
				'local_user_id' => $user,
				'account_did' => 'did:plc:' . $user,
				'subject_did' => 'did:plc:followed-by-' . $user,
				'rkey' => 'follow-' . $user,
				'uri' => 'at://did:plc:' . $user . '/app.bsky.graph.follow/follow-' . $user,
				'cid' => 'bafy' . $user,
				'state' => 'active',
				'followed_at' => '2026-11-16T10:52:00Z',
			]);
			$this->insert(AtprotoInteractionSchema::TABLE, [
				'local_user_id' => $user,
				'account_did' => 'did:plc:' . $user,
				'post_id' => 'interaction-' . $user[0],
				'kind' => 'like',
				'rkey' => 'like-' . $user,
				'uri' => 'at://did:plc:' . $user . '/app.bsky.feed.like/like-' . $user,
				'created_at' => '2026-11-16T10:52:00Z',
			]);
		}

		$this->insert(AccountListSchema::MEMBER_TABLE, [
			'list_id' => 'list-alice', 'account_id' => 'bob', 'created_at' => '2026-11-16T10:52:00Z',
		]);
		$this->insert(AccountListSchema::MEMBER_TABLE, [
			'list_id' => 'list-bob', 'account_id' => 'alice', 'created_at' => '2026-11-16T10:53:00Z',
		]);
		$this->insert(AccountMoveSchema::TABLE, [
			'event_id' => 'move-a',
			'origin_actor_id' => 'https://social.example/@alice',
			'target_actor_id' => 'https://social.example/@bob',
			'target_inbox_url' => 'https://social.example/@bob/inbox',
			'direction' => 'out',
			'state' => 'pending',
			'created_at' => '2026-11-16T10:54:00Z',
			'updated_at' => '2026-11-16T10:54:00Z',
		]);
		$this->insert(AccountMoveSchema::TABLE, [
			'event_id' => 'move-b',
			'origin_actor_id' => 'https://social.example/@bob',
			'target_actor_id' => 'https://social.example/@alice',
			'target_inbox_url' => 'https://social.example/@alice/inbox',
			'direction' => 'out',
			'state' => 'pending',
			'created_at' => '2026-11-16T10:55:00Z',
			'updated_at' => '2026-11-16T10:55:00Z',
		]);

		foreach (['alice', 'bob'] as $user) {
			$this->insert(AttachmentSchema::TABLE, [
				'id' => 'att-' . $user[0],
				'owner_id' => $user,
				'storage_key' => 'media/att-' . $user[0] . '/original.jpg',
				'thumbnail_key' => 'media/att-' . $user[0] . '/thumbnail.jpg',
				'mime_type' => 'image/jpeg',
				'width' => 10,
				'height' => 10,
				'byte_size' => 10,
				'created_at' => '2026-11-16T10:56:00Z',
			]);
			$this->blobs->put('media/att-' . $user[0] . '/original.jpg', $user . '-media');
			$this->blobs->put('media/att-' . $user[0] . '/thumbnail.jpg', $user . '-thumb');

			$did = 'did:plc:' . $user;
			$this->insert(PdsSchema::IDENTITY_TABLE, [
				'local_user_id' => $user,
				'did' => $did,
				'handle' => $user . '.test',
				'signing_key_id' => 'key-' . $user[0],
				'rotation_key_id' => 'rotation-' . $user[0],
				'created_at' => '2026-11-16T10:57:00Z',
				'updated_at' => '2026-11-16T10:57:00Z',
			]);
			$this->insert(PdsSchema::KEY_TABLE, [
				'id' => 'key-' . $user[0],
				'local_user_id' => $user,
				'did' => $did,
				'role' => 'signing',
				'private_key_sealed' => 'sealed-secret-' . $user,
				'public_key' => 'public-' . $user,
				'public_key_multibase' => 'zPublic' . $user,
				'active' => 1,
				'created_at' => '2026-11-16T10:58:00Z',
			]);
			$this->insert(PdsSchema::RECORD_TABLE, [
				'did' => $did,
				'collection' => 'app.bsky.feed.post',
				'rkey' => 'record-' . $user[0],
				'cid' => 'cid-record-' . $user,
				'record_cbor' => 'cbor-' . $user,
				'created_at' => '2026-11-16T10:59:00Z',
				'updated_at' => '2026-11-16T10:59:00Z',
			]);
			$this->insert(PdsSchema::COMMIT_TABLE, [
				'did' => $did,
				'cid' => 'commit-' . $user[0],
				'seq' => 1,
				'rev' => 'rev-' . $user,
				'data_cid' => 'data-' . $user,
				'prev_cid' => null,
				'signing_key_id' => 'key-' . $user[0],
				'commit_cbor' => 'commit-cbor-' . $user,
				'created_at' => '2026-11-16T11:00:00Z',
			]);
			$this->insert(PdsSchema::BLOCK_TABLE, [
				'did' => $did, 'cid' => 'block-' . $user, 'block_cbor' => 'block-cbor-' . $user,
			]);
			$this->insert(PdsSchema::PLC_TABLE, [
				'did' => $did,
				'cid' => 'plc-' . $user,
				'seq' => 1,
				'operation_cbor' => 'plc-cbor-' . $user,
				'created_at' => '2026-11-16T11:01:00Z',
			]);
			$this->insert(PdsSchema::BLOB_TABLE, [
				'did' => $did,
				'cid' => 'blob-' . $user[0],
				'mime_type' => 'image/jpeg',
				'size' => 10,
				'created_at' => '2026-11-16T11:02:00Z',
			]);
			$this->insert(PdsSchema::EVENT_TABLE, [
				'seq' => $user === 'alice' ? 1 : 2,
				'did' => $did,
				'commit_cid' => 'event-' . $user[0],
				'rev' => 'rev-' . $user,
				'since_rev' => 'since-' . $user,
				'ops_json' => '[]',
				'blocks_car' => 'car-' . $user,
				'blobs_json' => '[]',
				'created_at' => '2026-11-16T11:03:00Z',
			]);
			$this->blobs->put(
				'pds/blobs/' . hash('sha256', $did) . '/blob-' . $user[0] . '.bin',
				'blob-' . $user,
			);
		}
	}

	/**
	 * @param array<string, scalar|null> $row
	 */
	private function insert(string $table, array $row): void
	{
		$columns = array_keys($row);
		$placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
		$this->database->execute(
			'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
			$row,
		);
	}
}

final class FailOnceBlobStore implements BlobStore
{
	private bool $failed = false;

	public function __construct(
		private readonly InMemoryBlobStore $inner,
		private readonly string $pathToFail,
	) {
	}

	public function put(string $path, string $contents): void
	{
		$this->inner->put($path, $contents);
	}

	public function read(string $path): string
	{
		return $this->inner->read($path);
	}

	public function exists(string $path): bool
	{
		return $this->inner->exists($path);
	}

	public function delete(string $path): void
	{
		if (!$this->failed && $path === $this->pathToFail) {
			$this->failed = true;
			throw new \RuntimeException('Simulated temporary object-store failure.');
		}

		$this->inner->delete($path);
	}

	public function size(string $path): int
	{
		return $this->inner->size($path);
	}

	public function list(string $prefix = ''): array
	{
		return $this->inner->list($prefix);
	}
}
