<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryHomeTimelineRepository;
use AlohaSocial\Core\Support\Time;

final class InMemoryHomeTimelineRepositoryTest extends HomeTimelineRepositoryTestCase
{
	private InMemoryPostRepository $posts;

	private InMemoryRemotePostRepository $remotePosts;

	private InMemoryFollowingRepository $following;

	private InMemoryBlockRepository $blocks;

	private InMemoryMuteRepository $mutes;

	private HomeTimelineRepository $repository;

	private int $followCounter = 0;

	private int $blockCounter = 0;

	private int $muteCounter = 0;

	protected function setUp(): void
	{
		$this->posts = new InMemoryPostRepository();
		$this->remotePosts = new InMemoryRemotePostRepository();
		$this->following = new InMemoryFollowingRepository();
		$this->blocks = new InMemoryBlockRepository();
		$this->mutes = new InMemoryMuteRepository();
		$this->repository = new InMemoryHomeTimelineRepository(
			$this->posts,
			$this->remotePosts,
			$this->following,
			$this->blocks,
			$this->mutes,
		);
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
