<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\RemoteDomainCount;
use AlohaSocial\Core\Domain\Federation\RemoteEventMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteEventStatus;
use AlohaSocial\Core\Domain\Federation\RemoteMediaMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteObjectMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both remote-post repositories must satisfy.
 */
abstract class RemotePostRepositoryTestCase extends TestCase
{
	abstract protected function repository(): RemotePostRepository;

	public function testInsertAndFindByActivityId(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('activity-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));

		$found = $repository->findByActivityId('activity-1');

		self::assertNotNull($found);
		self::assertSame('activity-1', $found->activityId);
		self::assertSame('note-1', $found->objectId);
		self::assertSame('bob', $found->actorId);
		self::assertSame(RemotePostKind::Post, $found->kind);
		self::assertSame('content of activity-1', $found->content);
		self::assertSame('2026-11-11T10:00:00Z', Time::format($found->publishedAt));
		self::assertNull($found->deletedAt);
		self::assertNull($repository->findByActivityId('missing'));
	}

	public function testTypedMetadataSurvivesStorage(): void
	{
		$repository = $this->repository();
		$repository->insert(new RemotePost(
			activityId: 'event-activity',
			objectId: 'https://gancio.example/events/1',
			actorId: 'https://gancio.example',
			kind: RemotePostKind::Post,
			content: '',
			contentWarning: null,
			publishedAt: Time::parse('2026-11-11T10:00:00Z'),
			objectAuthorId: 'https://gancio.example',
			objectType: RemoteObjectType::Event,
			metadata: new RemoteObjectMetadata(
				title: 'Concert',
				event: new RemoteEventMetadata(
					name: 'Concert',
					startsAt: Time::parse('2026-12-01T18:00:00Z'),
					endsAt: Time::parse('2026-12-01T20:00:00Z'),
					location: 'Town hall',
					participationUrl: 'https://mobilizon.example/events/1/participate',
					status: RemoteEventStatus::Confirmed,
				),
				media: new RemoteMediaMetadata(durationSeconds: 90, sources: ['video/mp4' => 'https://cdn.example/v.mp4']),
			),
		));

		$found = $repository->findByActivityId('event-activity');

		self::assertNotNull($found);
		self::assertSame(RemoteObjectType::Event, $found->objectType);
		self::assertSame('Concert', $found->metadata?->title);
		self::assertSame('Town hall', $found->metadata?->event?->location);
		self::assertSame(RemoteEventStatus::Confirmed, $found->metadata?->event?->status);
		self::assertSame('2026-12-01T18:00:00Z', Time::format($found->metadata->event->startsAt));
		self::assertSame(90, $found->metadata?->media?->durationSeconds);
		self::assertSame(['video/mp4' => 'https://cdn.example/v.mp4'], $found->metadata?->media?->sources);
	}

	public function testAPostWithoutMetadataStaysANote(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('activity-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));

		$found = $repository->findByActivityId('activity-1');

		self::assertNotNull($found);
		self::assertSame(RemoteObjectType::Note, $found->objectType);
		self::assertNull($found->metadata);
	}

	public function testCountsDistinctDomainsFromLiveRemoteActors(): void
	{
		$repository = $this->repository();
		self::assertInstanceOf(RemoteDomainCount::class, $repository);

		$repository->insert($this->remote('one', 'note-1', 'https://one.example/users/bob', '2026-11-11T10:00:00Z'));
		$repository->insert($this->remote('two', 'note-2', 'https://one.example/users/carol', '2026-11-11T10:01:00Z'));
		$repository->insert($this->remote('three', 'note-3', 'https://two.example/users/dave', '2026-11-11T10:02:00Z'));
		$repository->softDeleteByActivityId(
			'three',
			'https://two.example/users/dave',
			Time::parse('2026-11-11T11:00:00Z'),
		);

		self::assertSame(1, $repository->remoteDomainCount());
	}

	public function testActivityIdIsUnique(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('activity-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));

		$this->expectException(DatabaseException::class);

		$repository->insert($this->remote('activity-1', 'note-2', 'bob', '2026-11-11T10:01:00Z'));
	}

	public function testFindPostByObjectIdIgnoresBoostsAndDeletedPosts(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('post-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));
		$repository->insert($this->remote(
			'boost-1',
			'note-1',
			'carol',
			'2026-11-11T10:01:00Z',
			RemotePostKind::Boost,
		));

		self::assertSame('post-1', $repository->findPostByObjectId('note-1')?->activityId);

		$repository->softDeleteByActivityId('post-1', 'bob', Time::parse('2026-11-11T11:00:00Z'));

		self::assertNull($repository->findPostByObjectId('note-1'));
		self::assertNotNull($repository->findByActivityId('post-1'));
	}

	public function testSoftDeleteByActivityIsActorScopedAndIdempotent(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('activity-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));

		self::assertFalse($repository->softDeleteByActivityId(
			'activity-1',
			'carol',
			Time::parse('2026-11-11T11:00:00Z'),
		));
		self::assertNull($repository->findByActivityId('activity-1')?->deletedAt);

		self::assertTrue($repository->softDeleteByActivityId(
			'activity-1',
			'bob',
			Time::parse('2026-11-11T11:00:00Z'),
		));
		self::assertSame(
			'2026-11-11T11:00:00Z',
			Time::format($repository->findByActivityId('activity-1')->deletedAt),
		);
		self::assertFalse($repository->softDeleteByActivityId(
			'activity-1',
			'bob',
			Time::parse('2026-11-11T12:00:00Z'),
		));
	}

	public function testSoftDeleteByObjectIsActorScopedAndOnlyDeletesAPost(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('post-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));
		$repository->insert($this->remote(
			'boost-1',
			'note-1',
			'carol',
			'2026-11-11T10:01:00Z',
			RemotePostKind::Boost,
		));

		self::assertFalse($repository->softDeletePostByObjectId(
			'note-1',
			'carol',
			Time::parse('2026-11-11T11:00:00Z'),
		));
		self::assertNull($repository->findByActivityId('boost-1')?->deletedAt);

		self::assertTrue($repository->softDeletePostByObjectId(
			'note-1',
			'bob',
			Time::parse('2026-11-11T11:00:00Z'),
		));
		self::assertNotNull($repository->findByActivityId('post-1')?->deletedAt);
	}

	public function testTimelineForActorsIsOrderedScopedAndExcludesDeletedRows(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('a-old', 'note-old', 'bob', '2026-11-11T10:00:00Z'));
		$repository->insert($this->remote('a-new', 'note-new', 'bob', '2026-11-11T10:02:00Z'));
		$repository->insert($this->remote('carol-post', 'note-carol', 'carol', '2026-11-11T10:03:00Z'));
		$repository->insert($this->remote('gone', 'note-gone', 'bob', '2026-11-11T10:04:00Z'));
		$repository->softDeleteByActivityId('gone', 'bob', Time::parse('2026-11-11T11:00:00Z'));

		$posts = $repository->timelineForActors(['bob', 'bob'], 10);

		self::assertSame(
			['a-new', 'a-old'],
			array_map(static fn (RemotePost $post): string => $post->activityId, $posts),
		);
		self::assertSame([], $repository->timelineForActors([], 10));
		self::assertSame([], $repository->timelineForActors(['nobody'], 10));
	}

	public function testAttachmentLinksAreOrderedReplacedAndLimited(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('post-1', 'note-1', 'bob', '2026-11-11T10:00:00Z'));

		$repository->linkAttachments('post-1', ['attachment-b', 'attachment-a']);

		self::assertSame(
			['post-1' => ['attachment-b', 'attachment-a']],
			$repository->attachmentIdsFor(['post-1', 'missing']),
		);
		self::assertTrue($repository->isAttachmentLinked('attachment-b'));
		self::assertFalse($repository->isAttachmentLinked('missing'));

		$repository->linkAttachments('post-1', ['attachment-c']);

		self::assertSame(
			['post-1' => ['attachment-c']],
			$repository->attachmentIdsFor(['post-1']),
		);
		self::assertTrue($repository->isAttachmentLinked('attachment-c'));
		self::assertFalse($repository->isAttachmentLinked('attachment-b'));

		$repository->linkAttachments('post-1', []);

		self::assertSame([], $repository->attachmentIdsFor(['post-1']));
		self::assertFalse($repository->isAttachmentLinked('attachment-c'));

		$this->expectException(DatabaseException::class);

		$repository->linkAttachments('post-1', ['1', '2', '3', '4', '5']);
	}

	public function testTimelineForActorsSupportsBeforeAndLimit(): void
	{
		$repository = $this->repository();
		$repository->insert($this->remote('one', 'note-1', 'bob', '2026-11-11T10:00:00Z'));
		$repository->insert($this->remote('two', 'note-2', 'bob', '2026-11-11T10:01:00Z'));
		$repository->insert($this->remote('three', 'note-3', 'bob', '2026-11-11T10:02:00Z'));

		self::assertSame(
			['three', 'two'],
			array_map(
				static fn (RemotePost $post): string => $post->activityId,
				$repository->timelineForActors(['bob'], 2),
			),
		);
		self::assertSame(
			['two', 'one'],
			array_map(
				static fn (RemotePost $post): string => $post->activityId,
				$repository->timelineForActors(['bob'], 10, Time::parse('2026-11-11T10:02:00Z')),
			),
		);
	}

	protected function remote(
		string $activityId,
		string $objectId,
		string $actorId,
		string $publishedAt,
		RemotePostKind $kind = RemotePostKind::Post,
	): RemotePost {
		return new RemotePost(
			$activityId,
			$objectId,
			$actorId,
			$kind,
			'content of ' . $activityId,
			null,
			Time::parse($publishedAt),
			$actorId,
		);
	}
}
