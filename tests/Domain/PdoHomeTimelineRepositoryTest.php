<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowSchema;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoFollowRepository;
use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\BlockSchema;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowingSchema;
use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\MuteSchema;
use AlohaSocial\Core\Domain\Federation\PdoBlockRepository;
use AlohaSocial\Core\Domain\Federation\PdoFollowingRepository;
use AlohaSocial\Core\Domain\Federation\PdoMuteRepository;
use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PollSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\PdoHomeTimelineRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;

final class PdoHomeTimelineRepositoryTest extends HomeTimelineRepositoryTestCase
{
	private PdoPostRepository $posts;

	private PdoRemotePostRepository $remotePosts;

	private PdoFollowingRepository $following;

	private PdoBlockRepository $blocks;

	private PdoMuteRepository $mutes;

	private HomeTimelineRepository $repository;

	private PdoConnection $connection;

	private int $followCounter = 0;

	private int $blockCounter = 0;

	protected function setUp(): void
	{
		$connection = $this->connection = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($connection);
		PollSchema::create($connection);
		FollowingSchema::create($connection);
		AtprotoFollowSchema::create($connection);
		RemotePostSchema::create($connection);
		BlockSchema::create($connection);
		MuteSchema::create($connection);

		$this->posts = new PdoPostRepository($connection);
		$this->remotePosts = new PdoRemotePostRepository($connection);
		$this->following = new PdoFollowingRepository($connection);
		$this->blocks = new PdoBlockRepository($connection);
		$this->mutes = new PdoMuteRepository($connection);
		$this->repository = new PdoHomeTimelineRepository($connection);
	}

	public function testActiveAtprotoFollowAddsRemotePostsOnlyToThatUsersHomeTimeline(): void
	{
		$uri = 'at://did:plc:followed/app.bsky.feed.post/3kpost';
		$this->addRemote(new RemotePost(
			$uri,
			$uri,
			'did:plc:followed',
			\AlohaSocial\Core\Domain\Federation\RemotePostKind::Post,
			'Bluesky post',
			null,
			Time::parse('2026-11-11T10:00:00Z'),
		));
		(new PdoAtprotoFollowRepository($this->connection))->save(new AtprotoFollow(
			'alice', 'did:plc:alice', 'did:plc:followed', '3kfollow',
			'at://did:plc:alice/app.bsky.graph.follow/3kfollow', 'bafyfollow',
			AtprotoFollow::ACTIVE, Time::parse('2026-11-11T09:00:00Z'),
		));

		$aliceIds = array_map(static fn ($entry): string => $entry->id, $this->repository()->forUser('alice', 10));
		$bobIds = array_map(static fn ($entry): string => $entry->id, $this->repository()->forUser('bob', 10));
		self::assertSame([$uri], $aliceIds);
		self::assertSame([], $bobIds);
	}

	protected function repository(): HomeTimelineRepository
	{
		return $this->repository;
	}

	protected function addPost(Post $post): void
	{
		$this->posts->insert($post);
	}

	protected function addRemote(RemotePost $remote): void
	{
		$this->remotePosts->insert($remote);
	}

	protected function follow(string $localUserId, string $actorId): void
	{
		$this->following->insert(new FollowedActor(
			'f' . ++$this->followCounter,
			$localUserId,
			$actorId,
			$actorId . '/inbox',
			Time::parse('2026-11-11T09:00:00Z'),
		));
	}

	protected function block(string $localUserId, string $actorId): void
	{
		$this->blocks->insert(new Block(
			'b' . ++$this->blockCounter,
			$localUserId,
			$actorId,
			Time::parse('2026-11-11T09:30:00Z'),
		));
	}

	protected function mute(string $localUserId, string $actorId, ?string $expiresAt = null): void
	{
		$this->mutes->save(new Mute(
			userId: $localUserId,
			actorId: $actorId,
			notifications: false,
			createdAt: Time::parse('2026-11-11T09:45:00Z'),
			expiresAt: $expiresAt === null ? null : Time::parse($expiresAt),
		));
	}

	protected function deleteRemote(string $activityId, string $actorId): void
	{
		$this->remotePosts->softDeleteByActivityId(
			$activityId,
			$actorId,
			Time::parse('2026-11-11T11:00:00Z'),
		);
	}
}
