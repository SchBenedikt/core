<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

use function array_filter;
use function array_map;
use function array_values;
use function trim;

/**
 * Local account relationships.
 *
 * A local-to-local follow is a follower edge in the existing graph, marked as
 * local. Keeping it there means follower counts, the public followers
 * collection and the relationship lookup share one source of truth. The
 * marker keeps the edge out of the remote delivery inboxes.
 *
 * Remote follows stay in the outgoing graph. The two directions are therefore
 * represented exactly as the federation model already represents them:
 * `social_follower` answers "who follows me", `social_following` answers "whom
 * do I follow remotely".
 */
final class LocalRelationshipService
{
	public function __construct(
		private readonly FollowerRepository $followers,
		private readonly FollowingService $following,
		private readonly FollowerService $followerService,
		private readonly LocalActorDirectory $actors,
		private readonly ActivityFactory $activities,
		private readonly ?BlockRepository $blocks = null,
		private readonly ?NotificationService $notifications = null,
	) {
	}

	/**
	 * @throws ValidationException when the caller tries to follow itself
	 */
	public function follow(string $followerId, string $targetId): void
	{
		$followerId = $this->known($followerId);
		$targetId = $this->known($targetId);

		if ($followerId === $targetId) {
			throw new ValidationException(['id' => 'An account cannot follow itself.']);
		}

		$this->followerService->follow(
			new FollowActor(
				localUserId: $targetId,
				actorId: $this->activities->actorId($followerId),
				inboxUrl: $this->activities->sharedInboxId(),
			),
			local: true,
		);

		$this->notifications?->recordFollow($targetId, $followerId);
	}

	public function unfollow(string $followerId, string $targetId): bool
	{
		$followerId = $this->known($followerId);
		$targetId = $this->known($targetId);

		return $this->followerService->unfollowLocalByActor(
			$targetId,
			$this->activities->actorId($followerId),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function relationship(string $viewerId, string $targetId): array
	{
		$viewerId = $this->known($viewerId);
		$targetId = $this->known($targetId);

		$viewerActor = $this->activities->actorId($viewerId);
		$targetActor = $this->activities->actorId($targetId);

		return [
			'id' => $targetId,
			'following' => $this->followers->findByActor($targetId, $viewerActor)?->local === true,
			'showing_reblogs' => true,
			'notifying' => false,
			'languages' => [],
			'followed_by' => $this->followers->findByActor($viewerId, $targetActor)?->local === true,
			'blocking' => $this->blocks?->findByActor($viewerId, $targetActor) !== null,
			'blocked_by' => false,
			'muting' => false,
			'muting_notifications' => false,
			'requested' => false,
			'domain_blocking' => false,
			'endorsed' => false,
			'note' => '',
		];
	}

	/**
	 * @param list<string> $targetIds
	 *
	 * @return list<array<string, mixed>>
	 */
	public function relationships(string $viewerId, array $targetIds): array
	{
		return array_map(
			fn (string $targetId): array => $this->relationship($viewerId, $targetId),
			$targetIds,
		);
	}

	public function followersCount(string $userId): int
	{
		// Counts are also read while projecting an old post whose author may no
		// longer be in the host directory. They degrade to zero rather than
		// turning an otherwise readable status into a server error.
		return $this->followerService->count($userId);
	}

	public function followingCount(string $userId): int
	{
		return $this->following->count($userId)
			+ $this->followers->localFollowingCount($this->activities->actorId($userId));
	}

	/**
	 * The local accounts one account follows, for the home timeline.
	 *
	 * Local accounts the reader blocks are omitted without removing the edge;
	 * unblocking makes the follow visible again.
	 *
	 * @return list<string>
	 */
	public function localFollowingUserIds(string $userId): array
	{
		// A local read model may still serve an account that left the host's
		// directory; it then simply has no local follows to add.
		$targetIds = $this->followers->localUserIdsFollowedBy(
			$this->activities->actorId($userId),
		);

		if ($this->blocks === null) {
			return $targetIds;
		}

		return array_values(array_filter(
			$targetIds,
			fn (string $targetId): bool => $this->blocks?->findByActor(
				$userId,
				$this->activities->actorId($targetId),
			) === null,
		));
	}

	/**
	 * The local actors one account follows, for the public following view.
	 *
	 * @return list<string>
	 */
	public function localFollowingActorIds(string $userId): array
	{
		$userId = $this->known($userId);

		return array_map(
			fn (string $targetId): string => $this->activities->actorId($targetId),
			$this->followers->localUserIdsFollowedBy($this->activities->actorId($userId)),
		);
	}

	private function known(string $userId): string
	{
		$userId = trim($userId);
		if ($userId === '' || !$this->actors->has($userId)) {
			throw new UnknownLocalActorException($userId);
		}

		return $userId;
	}
}
