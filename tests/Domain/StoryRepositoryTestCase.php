<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Domain\Story\StoryReaction;
use AlohaSocial\Core\Domain\Story\StoryReply;
use AlohaSocial\Core\Domain\Story\StoryRepository;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both story repositories must satisfy.
 */
abstract class StoryRepositoryTestCase extends TestCase
{
	abstract protected function repository(): StoryRepository;

	public function testInsertAndFind(): void
	{
		$repository = $this->repository();
		$story = $this->story('s1', 'alice', '2026-11-11T10:00:00Z', '2026-11-12T10:00:00Z');

		$repository->insert($story);

		self::assertSame('s1', $repository->find('s1')?->id);
		self::assertSame('https://social.example/stories/s1', $repository->findByObjectId('https://social.example/stories/s1')?->objectId);
		self::assertSame('a1', $repository->findByAttachmentId('a1')?->attachmentId);
		self::assertNull($repository->find('unknown'));
	}

	public function testActiveForOwnersExcludesExpiredAndDeletedRows(): void
	{
		$repository = $this->repository();
		$repository->insert($this->story('old', 'alice', '2026-11-10T08:00:00Z', '2026-11-11T08:00:00Z'));
		$repository->insert($this->story('deleted', 'alice', '2026-11-11T09:00:00Z', '2026-11-12T09:00:00Z'));
		$repository->insert($this->story('fresh', 'alice', '2026-11-11T10:00:00Z', '2026-11-12T10:00:00Z'));
		$repository->softDelete('deleted', Time::parse('2026-11-11T10:30:00Z'));

		$active = $repository->activeForOwners(['alice'], Time::parse('2026-11-11T11:00:00Z'), 10);

		self::assertCount(1, $active);
		self::assertSame('fresh', $active[0]->id);
	}

	public function testExpiredBeforeReturnsOnlyUndeletedRows(): void
	{
		$repository = $this->repository();
		$repository->insert($this->story('old', 'alice', '2026-11-10T08:00:00Z', '2026-11-11T08:00:00Z'));
		$repository->insert($this->story('fresh', 'alice', '2026-11-11T10:00:00Z', '2026-11-12T10:00:00Z'));

		$expired = $repository->expiredBefore(Time::parse('2026-11-11T11:00:00Z'), 10);

		self::assertCount(1, $expired);
		self::assertSame('old', $expired[0]->id);
		self::assertTrue($repository->softDelete('old', Time::parse('2026-11-11T11:00:00Z')));
		self::assertSame([], $repository->expiredBefore(Time::parse('2026-11-11T11:00:00Z'), 10));
	}

	public function testViewsAreUniquePerViewer(): void
	{
		$repository = $this->repository();
		$repository->insert($this->story('s1', 'alice', '2026-11-11T10:00:00Z', '2026-11-12T10:00:00Z'));

		$repository->recordView('s1', 'bob', Time::parse('2026-11-11T10:05:00Z'));
		$repository->recordView('s1', 'bob', Time::parse('2026-11-11T10:06:00Z'));
		$repository->recordView('s1', 'carol', Time::parse('2026-11-11T10:07:00Z'));

		$viewers = $repository->viewers('s1');
		self::assertCount(2, $viewers);
		$byViewer = array_column($viewers, 'viewed_at', 'viewer_id');
		self::assertSame('2026-11-11T10:05:00Z', $byViewer['bob']);
		self::assertSame('2026-11-11T10:07:00Z', $byViewer['carol']);
	}

	public function testReactionsUpsertAndRemove(): void
	{
		$repository = $this->repository();
		$repository->insert($this->story('s1', 'alice', '2026-11-11T10:00:00Z', '2026-11-12T10:00:00Z'));

		$repository->saveReaction(new StoryReaction('s1', 'bob', '❤️', Time::parse('2026-11-11T10:05:00Z')));
		$repository->saveReaction(new StoryReaction('s1', 'bob', '❤️', Time::parse('2026-11-11T10:06:00Z')));
		$repository->saveReaction(new StoryReaction('s1', 'bob', '🔥', Time::parse('2026-11-11T10:07:00Z')));

		self::assertCount(2, $repository->reactions('s1'));
		self::assertTrue($repository->removeReaction('s1', 'bob', '🔥'));
		self::assertCount(1, $repository->reactions('s1'));
		self::assertFalse($repository->removeReaction('s1', 'bob', '🔥'));
	}

	public function testRepliesAreBoundedAndNewestFirst(): void
	{
		$repository = $this->repository();
		$repository->insert($this->story('s1', 'alice', '2026-11-11T10:00:00Z', '2026-11-12T10:00:00Z'));
		$repository->saveReply(new StoryReply('r1', 's1', 'bob', 'first', Time::parse('2026-11-11T10:05:00Z')));
		$repository->saveReply(new StoryReply('r2', 's1', 'carol', 'second', Time::parse('2026-11-11T10:06:00Z')));

		$replies = $repository->replies('s1', 1);

		self::assertCount(1, $replies);
		self::assertSame('r2', $replies[0]->id);
	}

	public function testTombstoneBlocksAReplayedAdd(): void
	{
		$repository = $this->repository();
		$repository->markTombstone('https://remote.example/stories/1', 'https://remote.example/users/alice', Time::parse('2026-11-11T10:00:00Z'));

		self::assertTrue($repository->isTombstoned('https://remote.example/stories/1', 'https://remote.example/users/alice'));
		self::assertFalse($repository->isTombstoned('https://remote.example/stories/1', 'https://remote.example/users/bob'));
	}

	private function story(string $id, string $ownerId, string $createdAt, string $expiresAt): Story
	{
		return new Story(
			$id,
			$ownerId,
			true,
			'https://social.example/stories/' . $id,
			'caption',
			Time::parse($createdAt),
			Time::parse($expiresAt),
			'a1',
		);
	}
}
