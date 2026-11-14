<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPost;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostRepository;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostState;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle rules both scheduled-post adapters must share.
 *
 * The claim cases are the ones that matter for safety: a row is handed to one
 * worker, a crashed worker's lease expires, and a cancel or reschedule that
 * loses against the publisher must not resurrect the row.
 */
abstract class ScheduledPostRepositoryTestCase extends TestCase
{
	abstract protected function repository(): ScheduledPostRepository;

	public function testAStoredScheduleCanBeReadBackWithItsInput(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());

		$found = $repository->find('schedule-1');

		self::assertNotNull($found);
		self::assertSame('alice', $found->authorId);
		self::assertSame('post-1', $found->postId);
		self::assertSame(Visibility::Public, $found->visibility);
		self::assertSame(['attachment-1'], $found->attachmentIds);
		self::assertSame(ScheduledPostState::Scheduled, $found->state);
		self::assertSame('2026-11-11T10:10:00Z', Time::format($found->scheduledAt));
	}

	public function testTheReservedPostIdCannotBeUsedTwice(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());

		$this->expectException(DatabaseException::class);

		$repository->insert($this->scheduled('schedule-2', 'post-1'));
	}

	public function testOnlyDuePendingSchedulesAreListed(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());
		$repository->insert($this->scheduled('schedule-later', 'post-later', '2026-11-11T12:00:00Z'));

		self::assertSame(
			[],
			$repository->dueIds(Time::parse('2026-11-11T10:05:00Z'), 10),
		);
		self::assertSame(
			['schedule-1'],
			$repository->dueIds(Time::parse('2026-11-11T10:10:00Z'), 10),
		);
		self::assertSame(
			['schedule-1', 'schedule-later'],
			$repository->dueIds(Time::parse('2026-11-11T12:00:00Z'), 10),
		);
	}

	public function testAClaimGoesToOneWorkerOnly(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());

		$now = Time::parse('2026-11-11T10:10:00Z');
		$lease = $now->modify('+300 seconds');

		self::assertTrue($repository->claim('schedule-1', $now, $lease));
		self::assertFalse($repository->claim('schedule-1', $now, $lease));
		self::assertSame(ScheduledPostState::Publishing, $repository->find('schedule-1')?->state);
	}

	public function testAnExpiredLeaseCanBeClaimedAgain(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());

		$now = Time::parse('2026-11-11T10:10:00Z');
		self::assertTrue($repository->claim('schedule-1', $now, $now->modify('+300 seconds')));

		self::assertFalse($repository->claim(
			'schedule-1',
			$now->modify('+299 seconds'),
			$now->modify('+600 seconds'),
		));
		self::assertTrue($repository->claim(
			'schedule-1',
			$now->modify('+300 seconds'),
			$now->modify('+600 seconds'),
		));
	}

	public function testCancelWinsAgainstAClaimedRowAndStopsFurtherClaims(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());

		self::assertTrue($repository->cancel('schedule-1'));
		self::assertFalse($repository->claim(
			'schedule-1',
			Time::parse('2026-11-11T10:10:00Z'),
			Time::parse('2026-11-11T10:15:00Z'),
		));
		self::assertSame(ScheduledPostState::Cancelled, $repository->find('schedule-1')?->state);
	}

	public function testAPublishedRowCanNoLongerBeCancelledOrRescheduled(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());
		$repository->markPublished('schedule-1', Time::parse('2026-11-11T10:10:00Z'));

		self::assertFalse($repository->cancel('schedule-1'));
		self::assertFalse($repository->reschedule('schedule-1', Time::parse('2026-11-12T10:00:00Z')));
	}

	public function testRescheduleOnlyTouchesAPendingRow(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());

		self::assertTrue($repository->reschedule('schedule-1', Time::parse('2026-11-13T09:00:00Z')));
		self::assertSame(
			'2026-11-13T09:00:00Z',
			Time::format($repository->find('schedule-1')?->scheduledAt ?? new DateTimeImmutable()),
		);
	}

	public function testTheQuotaCountsEveryCreatedRow(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());
		$repository->insert($this->scheduled('schedule-2', 'post-2'));
		$repository->cancel('schedule-2');

		self::assertSame(2, $repository->countCreatedSince('alice', Time::parse('2026-11-10T00:00:00Z')));
		self::assertSame(0, $repository->countCreatedSince('bob', Time::parse('2026-11-10T00:00:00Z')));
	}

	public function testTheAuthorPageIsNewestFirstAndCursorBound(): void
	{
		$repository = $this->repository();
		$repository->insert($this->scheduled());
		$repository->insert($this->scheduled('schedule-2', 'post-2', '2026-11-11T11:00:00Z', '2026-11-11T10:02:00Z'));
		$repository->insert($this->scheduled('schedule-other', 'post-other', '2026-11-11T11:00:00Z', '2026-11-11T10:03:00Z', 'bob'));

		$page = $repository->forAuthor('alice', 10);
		self::assertSame(['schedule-2', 'schedule-1'], array_map(
			static fn (ScheduledPost $row): string => $row->id,
			$page,
		));

		$next = $repository->forAuthor('alice', 10, Time::parse('2026-11-11T10:02:00Z'), 'schedule-2');
		self::assertSame(['schedule-1'], array_map(
			static fn (ScheduledPost $row): string => $row->id,
			$next,
		));

		self::assertSame(1, count($repository->forAuthor('bob', 10)));
	}

	private function scheduled(
		string $id = 'schedule-1',
		string $postId = 'post-1',
		string $scheduledAt = '2026-11-11T10:10:00Z',
		string $createdAt = '2026-11-11T10:00:00Z',
		string $authorId = 'alice',
	): ScheduledPost {
		return new ScheduledPost(
			id: $id,
			authorId: $authorId,
			postId: $postId,
			content: 'Scheduled hello',
			visibility: Visibility::Public,
			scheduledAt: Time::parse($scheduledAt),
			createdAt: Time::parse($createdAt),
			attachmentIds: ['attachment-1'],
		);
	}
}
