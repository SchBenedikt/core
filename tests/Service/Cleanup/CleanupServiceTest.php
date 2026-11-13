<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Cleanup;

use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Service\Cleanup\CleanupJobHandler;
use AlohaSocial\Core\Service\Cleanup\CleanupOptions;
use AlohaSocial\Core\Service\Cleanup\CleanupReport;
use AlohaSocial\Core\Service\Cleanup\CleanupResult;
use AlohaSocial\Core\Service\Cleanup\CleanupService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Retention rules and the safety properties of the cleanup job.
 */
final class CleanupServiceTest extends TestCase
{
	private MutableClock $clock;

	private InMemoryPostRepository $posts;

	private InMemoryAttachmentRepository $attachments;

	private InMemoryRemotePostRepository $remotePosts;

	private InMemoryNotificationRepository $notifications;

	private InMemoryRemoteThreadRepository $threads;

	private InMemoryBlobStore $blobs;

	private CleanupService $cleanup;

	protected function setUp(): void
	{
		$this->clock = new MutableClock('2026-01-01T00:00:00Z');
		$this->posts = new InMemoryPostRepository();
		$this->remotePosts = new InMemoryRemotePostRepository();
		$this->attachments = new InMemoryAttachmentRepository($this->posts, $this->remotePosts);
		$this->notifications = new InMemoryNotificationRepository();
		$this->threads = new InMemoryRemoteThreadRepository();
		$this->blobs = new InMemoryBlobStore();
		$this->cleanup = new CleanupService(
			$this->attachments,
			$this->notifications,
			$this->threads,
			$this->blobs,
			$this->clock,
		);
	}

	public function testDryRunThenRealRunDeletesOnlyExpiredRowsAndTheirBlobs(): void
	{
		$oldAttachment = $this->attachment('old', '2026-01-01T00:00:00Z');
		$this->attachments->insert($oldAttachment);
		$this->putBlobs($oldAttachment);

		$linkedAttachment = $this->attachment('linked', '2026-01-01T00:00:00Z');
		$this->attachments->insert($linkedAttachment);
		$this->putBlobs($linkedAttachment);
		$this->posts->insert(new Post(
			id: 'post-1',
			authorId: 'alice',
			content: 'links media',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-01-01T00:00:00Z'),
			attachmentIds: ['linked'],
		));

		$remoteLinkedAttachment = $this->attachment('remote-linked', '2026-01-01T00:00:00Z');
		$this->attachments->insert($remoteLinkedAttachment);
		$this->putBlobs($remoteLinkedAttachment);
		$this->remotePosts->insert(new RemotePost(
			activityId: 'https://remote.example/activities/cleanup',
			objectId: 'https://remote.example/notes/cleanup',
			actorId: 'https://remote.example/users/bob',
			kind: RemotePostKind::Post,
			content: 'remote post with a shared image',
			contentWarning: null,
			publishedAt: Time::parse('2026-01-01T00:00:00Z'),
			objectAuthorId: 'https://remote.example/users/bob',
		));
		$this->remotePosts->linkAttachments('https://remote.example/activities/cleanup', ['remote-linked']);

		$this->notifications->insert($this->notification('old-read', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'));
		$this->notifications->insert($this->notification('old-unread', '2026-01-01T00:00:00Z'));
		$this->threads->upsert($this->thread('old-thread', '2026-01-01T00:00:00Z'));

		$this->clock->advance(60 * 86400);

		$boundaryAttachment = $this->attachment('boundary', '2026-01-31T00:00:00Z');
		$this->attachments->insert($boundaryAttachment);
		$this->putBlobs($boundaryAttachment);
		$this->notifications->insert($this->notification('boundary-read', '2026-01-31T00:00:00Z', '2026-01-31T00:00:00Z'));
		$this->threads->upsert($this->thread('boundary-thread', '2026-01-31T00:00:00Z'));

		$freshAttachment = $this->attachment('fresh', '2026-03-02T00:00:00Z');
		$this->attachments->insert($freshAttachment);
		$this->putBlobs($freshAttachment);
		$this->notifications->insert($this->notification('fresh-read', '2026-03-02T00:00:00Z', '2026-03-02T00:00:00Z'));
		$this->threads->upsert($this->thread('fresh-thread', '2026-03-02T00:00:00Z'));

		$options = new CleanupOptions(
			kind: CleanupOptions::KIND_ALL,
			mediaRetentionDays: 30,
			notificationRetentionDays: 30,
			threadRetentionDays: 30,
			limit: 100,
		);

		$preview = $this->cleanup->run(new CleanupOptions(
			kind: $options->kind,
			mediaRetentionDays: $options->mediaRetentionDays,
			notificationRetentionDays: $options->notificationRetentionDays,
			threadRetentionDays: $options->threadRetentionDays,
			limit: $options->limit,
			dryRun: true,
		));

		self::assertSame(3, $preview->matched());
		self::assertSame(0, $preview->deleted());
		self::assertNotNull($this->attachments->find('old'));
		self::assertNotNull($this->notifications->find('old-read'));

		$report = $this->cleanup->run($options);

		self::assertSame(3, $report->matched());
		self::assertSame(3, $report->deleted());
		self::assertSame(0, $report->remaining());
		self::assertNull($this->attachments->find('old'));
		self::assertNotNull($this->attachments->find('linked'));
		self::assertNotNull($this->attachments->find('remote-linked'));
		self::assertNotNull($this->attachments->find('boundary'));
		self::assertNotNull($this->attachments->find('fresh'));
		self::assertFalse($this->blobs->exists($oldAttachment->storageKey));
		self::assertFalse($this->blobs->exists($oldAttachment->thumbnailKey));
		self::assertTrue($this->blobs->exists($linkedAttachment->storageKey));
		self::assertTrue($this->blobs->exists($remoteLinkedAttachment->storageKey));
		self::assertTrue($this->blobs->exists($boundaryAttachment->storageKey));
		self::assertTrue($this->blobs->exists($freshAttachment->storageKey));
		self::assertNull($this->notifications->find('old-read'));
		self::assertNotNull($this->notifications->find('old-unread'));
		self::assertNotNull($this->notifications->find('boundary-read'));
		self::assertNotNull($this->notifications->find('fresh-read'));
		self::assertNull($this->threads->find('old-thread'));
		self::assertNotNull($this->threads->find('boundary-thread'));
		self::assertNotNull($this->threads->find('fresh-thread'));

		$repeat = $this->cleanup->run($options);
		self::assertSame(0, $repeat->matched());
		self::assertSame(0, $repeat->deleted());
	}

	public function testLimitIsAppliedPerKindAndTheNextRunContinues(): void
	{
		foreach (['a', 'b', 'c'] as $id) {
			$attachment = $this->attachment($id, '2026-01-01T00:00:00Z');
			$this->attachments->insert($attachment);
			$this->putBlobs($attachment);
		}
		$this->clock->advance(60 * 86400);

		$options = new CleanupOptions(
			kind: CleanupOptions::KIND_MEDIA,
			mediaRetentionDays: 30,
			limit: 1,
		);

		$first = $this->cleanup->run($options);
		self::assertSame(3, $first->matched());
		self::assertSame(1, $first->deleted());
		self::assertSame(2, $first->remaining());
		self::assertNull($this->attachments->find('a'));
		self::assertNotNull($this->attachments->find('b'));
		self::assertNotNull($this->attachments->find('c'));

		$second = $this->cleanup->run($options);
		self::assertSame(1, $second->deleted());
		self::assertNull($this->attachments->find('b'));

		$third = $this->cleanup->run($options);
		self::assertSame(1, $third->deleted());
		self::assertNull($this->attachments->find('c'));

		self::assertSame(0, $this->cleanup->run($options)->deleted());
	}

	public function testMediaIsSkippedWithoutABlobStoreButOtherKindsStillRun(): void
	{
		$attachment = $this->attachment('old', '2026-01-01T00:00:00Z');
		$this->attachments->insert($attachment);
		$this->notifications->insert($this->notification('old-read', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'));
		$this->threads->upsert($this->thread('old-thread', '2026-01-01T00:00:00Z'));
		$this->clock->advance(60 * 86400);

		$cleanup = new CleanupService(
			$this->attachments,
			$this->notifications,
			$this->threads,
			null,
			$this->clock,
		);

		$report = $cleanup->run(new CleanupOptions(
			kind: CleanupOptions::KIND_ALL,
			mediaRetentionDays: 30,
			notificationRetentionDays: 30,
			threadRetentionDays: 30,
		));

		$media = $this->resultFor($report, CleanupOptions::KIND_MEDIA);
		self::assertSame('cleanup_media_unavailable', $media->skippedReason);
		self::assertSame(0, $media->deleted);
		self::assertNotNull($this->attachments->find('old'));
		self::assertNull($this->notifications->find('old-read'));
		self::assertNull($this->threads->find('old-thread'));
	}

	public function testMalformedJobPayloadCompletesWithoutTouchingData(): void
	{
		$attachment = $this->attachment('old', '2026-01-01T00:00:00Z');
		$this->attachments->insert($attachment);
		$this->putBlobs($attachment);
		$this->clock->advance(60 * 86400);

		$handler = new CleanupJobHandler($this->cleanup, new NullLogger());
		$handler->handle(new Job('job-1', JobType::CLEANUP, ['kind' => 'posts'], $this->clock->now()));

		self::assertNotNull($this->attachments->find('old'));
	}

	private function resultFor(CleanupReport $report, string $kind): CleanupResult
	{
		foreach ($report->results as $result) {
			if ($result->kind === $kind) {
				return $result;
			}
		}

		self::fail('Missing cleanup result for ' . $kind);
	}

	private function attachment(string $id, string $createdAt): Attachment
	{
		return new Attachment(
			id: $id,
			ownerId: 'alice',
			storageKey: 'media/' . $id . '/original.png',
			thumbnailKey: 'media/' . $id . '/thumbnail.png',
			mimeType: 'image/png',
			width: 800,
			height: 600,
			byteSize: 1234,
			altText: null,
			createdAt: Time::parse($createdAt),
		);
	}

	private function putBlobs(Attachment $attachment): void
	{
		$this->blobs->put($attachment->storageKey, 'original');
		$this->blobs->put($attachment->thumbnailKey, 'thumbnail');
	}

	private function notification(string $id, string $createdAt, ?string $readAt = null): Notification
	{
		return new Notification(
			id: $id,
			recipientId: 'alice',
			actorId: 'bob',
			type: NotificationType::Follow,
			postId: null,
			createdAt: Time::parse($createdAt),
			readAt: $readAt === null ? null : Time::parse($readAt),
		);
	}

	private function thread(string $objectId, string $fetchedAt): RemoteThreadPost
	{
		return new RemoteThreadPost(
			objectId: $objectId,
			authorId: 'https://remote.example/users/bob',
			content: 'cached thread node',
			publishedAt: Time::parse('2026-01-01T00:00:00Z'),
			fetchedAt: Time::parse($fetchedAt),
		);
	}
}
