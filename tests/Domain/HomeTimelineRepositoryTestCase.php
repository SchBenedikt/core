<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The contract both combined home-timeline repositories must satisfy.
 *
 * The important edge is that a local public post from somebody the reader does
 * not follow must not appear: the home timeline is the follow graph plus the
 * reader's own posts, not the public timeline.
 */
abstract class HomeTimelineRepositoryTestCase extends TestCase
{
	private const BOB = 'https://remote.example/users/bob';

	private const DAVE = 'https://remote.example/users/dave';

	abstract protected function repository(): HomeTimelineRepository;

	abstract protected function addPost(Post $post): void;

	abstract protected function addRemote(RemotePost $remote): void;

	abstract protected function follow(string $localUserId, string $actorId): void;

	abstract protected function block(string $localUserId, string $actorId): void;

	abstract protected function mute(string $localUserId, string $actorId, ?string $expiresAt = null): void;

	abstract protected function deleteRemote(string $activityId, string $actorId): void;

	public function testOwnAndFollowedRemoteEntriesAreMergedInTimeOrder(): void
	{
		$this->seed();

		$entries = $this->repository()->forUser('alice', 10);

		self::assertSame(
			['remote-boost', 'remote-post', 'own-public', 'own-private'],
			$this->ids($entries),
		);

		$boost = $entries[0];
		self::assertTrue($boost->isBoost());
		self::assertSame('boost', $boost->toArray()['kind']);
		self::assertSame(self::BOB, $boost->boostedBy);
		self::assertSame(self::DAVE, $boost->objectAuthorId);
		self::assertNull($boost->visibility);
		self::assertSame('boosted content', $boost->content);

		$remote = $entries[1];
		self::assertFalse($remote->isBoost());
		self::assertSame(self::BOB, $remote->authorId);
		self::assertSame('remote content', $remote->content);
		self::assertNull($remote->visibility);

		self::assertSame(Visibility::Public, $entries[2]->visibility);
		self::assertSame(Visibility::Followers, $entries[3]->visibility);
	}

	public function testAnArchivedOwnPostIsAbsentFromTheHomeTimeline(): void
	{
		$this->addPost($this->post('archived-own', 'alice', 'hidden content', '2026-11-11T10:02:00Z')
			->withArchivedAt(Time::parse('2026-11-11T11:00:00Z')));
		$this->addPost($this->post('visible-own', 'alice', 'visible content', '2026-11-11T10:03:00Z'));

		$ids = $this->ids($this->repository()->forUser('alice', 10));

		self::assertNotContains('archived-own', $ids);
		self::assertContains('visible-own', $ids);
	}

	public function testUnfollowedLocalPostsAndUnfollowedRemotePostsAreAbsent(): void
	{
		$this->seed();

		$entries = $this->repository()->forUser('alice', 10);

		self::assertNotContains('other-local', $this->ids($entries));
		self::assertNotContains('remote-eve', $this->ids($entries));
	}

	public function testBeforeIsExclusiveAndLimitIsApplied(): void
	{
		$this->seed();

		self::assertSame(
			['remote-post', 'own-public', 'own-private'],
			$this->ids($this->repository()->forUser('alice', 10, Time::parse('2026-11-11T10:05:00Z'))),
		);
		self::assertSame(
			['remote-boost', 'remote-post'],
			$this->ids($this->repository()->forUser('alice', 2)),
		);
	}

	public function testDeletingARemotePostRemovesOnlyThatEntry(): void
	{
		$this->seed();
		$this->deleteRemote('remote-post', self::BOB);

		$ids = $this->ids($this->repository()->forUser('alice', 10));

		self::assertNotContains('remote-post', $ids);
		self::assertContains('remote-boost', $ids);
	}

	public function testABlockedActorIsHiddenOnlyForTheReaderWhoBlockedIt(): void
	{
		$this->seed();
		$this->block('alice', self::BOB);

		$alice = $this->repository()->forUser('alice', 10);
		self::assertNotContains('remote-post', $this->ids($alice));
		self::assertNotContains('remote-boost', $this->ids($alice));
		self::assertContains('own-public', $this->ids($alice));

		$this->follow('carol', self::BOB);
		$this->addPost($this->post('carol-own', 'carol', 'carol content', '2026-11-11T10:07:00Z'));

		$carol = $this->repository()->forUser('carol', 10);
		self::assertContains('remote-post', $this->ids($carol));
		self::assertContains('carol-own', $this->ids($carol));
	}

	public function testABlockedObjectAuthorHidesABoostEvenWhenTheBoosterIsNotBlocked(): void
	{
		$this->seed();
		$this->block('alice', self::DAVE);

		$ids = $this->ids($this->repository()->forUser('alice', 10));

		self::assertNotContains('remote-boost', $ids);
		self::assertContains('remote-post', $ids);
	}

	public function testAMutedActorIsHiddenOnlyForTheReaderWhoMutedIt(): void
	{
		$this->seed();
		$this->mute('alice', self::BOB);

		$alice = $this->repository()->forUser('alice', 10);
		self::assertNotContains('remote-post', $this->ids($alice));
		self::assertNotContains('remote-boost', $this->ids($alice));
		self::assertContains('own-public', $this->ids($alice));

		$this->follow('carol', self::BOB);

		$carol = $this->repository()->forUser('carol', 10);
		self::assertContains('remote-post', $this->ids($carol));
		self::assertContains('remote-boost', $this->ids($carol));
	}

	public function testAMutedObjectAuthorHidesABoostEvenWhenTheBoosterIsNotMuted(): void
	{
		$this->seed();
		$this->mute('alice', self::DAVE);

		$ids = $this->ids($this->repository()->forUser('alice', 10));

		self::assertNotContains('remote-boost', $ids);
		self::assertContains('remote-post', $ids);
	}

	public function testAnExpiredMuteDoesNotHideAnything(): void
	{
		$this->seed();
		$this->mute('alice', self::BOB, '2000-01-01T00:00:00Z');

		$ids = $this->ids($this->repository()->forUser('alice', 10));

		self::assertContains('remote-post', $ids);
		self::assertContains('remote-boost', $ids);
	}

	public function testTheTimelineIsScopedToTheReader(): void
	{
		$this->seed();
		$this->follow('carol', self::BOB);
		$this->addPost($this->post('carol-own', 'carol', 'carol content', '2026-11-11T10:07:00Z'));

		$carol = $this->repository()->forUser('carol', 10);

		self::assertContains('carol-own', $this->ids($carol));
		self::assertContains('remote-post', $this->ids($carol));
		self::assertNotContains('own-public', $this->ids($carol));
	}

	private function seed(): void
	{
		$this->addPost($this->post('own-private', 'alice', 'private content', '2026-11-11T10:00:00Z', Visibility::Followers));
		$this->addPost($this->post('own-public', 'alice', 'public content', '2026-11-11T10:02:00Z'));
		$this->addPost($this->post('other-local', 'bob', 'local bob content', '2026-11-11T10:03:00Z'));

		$this->follow('alice', self::BOB);

		$this->addRemote($this->remote(
			'remote-post',
			'note-1',
			self::BOB,
			'remote content',
			'2026-11-11T10:04:00Z',
			RemotePostKind::Post,
		));
		$this->addRemote($this->remote(
			'remote-boost',
			'note-2',
			self::BOB,
			'boosted content',
			'2026-11-11T10:05:00Z',
			RemotePostKind::Boost,
			self::DAVE,
		));
		$this->addRemote($this->remote(
			'remote-eve',
			'note-3',
			'https://remote.example/users/eve',
			'eve content',
			'2026-11-11T10:06:00Z',
		));
	}

	private function post(
		string $id,
		string $authorId,
		string $content,
		string $publishedAt,
		Visibility $visibility = Visibility::Public,
	): Post {
		return new Post($id, $authorId, $content, $visibility, Time::parse($publishedAt));
	}

	private function remote(
		string $activityId,
		string $objectId,
		string $actorId,
		string $content,
		string $publishedAt,
		RemotePostKind $kind = RemotePostKind::Post,
		?string $objectAuthorId = null,
	): RemotePost {
		return new RemotePost(
			$activityId,
			$objectId,
			$actorId,
			$kind,
			$content,
			null,
			Time::parse($publishedAt),
			$objectAuthorId ?? $actorId,
		);
	}

	/**
	 * @param list<TimelineEntry> $entries
	 *
	 * @return list<string>
	 */
	private function ids(array $entries): array
	{
		return array_map(static fn (TimelineEntry $entry): string => $entry->id, $entries);
	}
}
