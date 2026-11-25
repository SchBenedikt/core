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
use function rawurldecode;
use function rtrim;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function trim;
use function usort;

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
		private readonly ?MuteService $mutes = null,
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
		$mute = $this->mutes?->find($viewerId, $targetId);

		return [
			'id' => $targetId,
			'following' => $this->followers->findByActor($targetId, $viewerActor)?->local === true,
			'showing_reblogs' => true,
			'notifying' => false,
			'languages' => [],
			'followed_by' => $this->followers->findByActor($viewerId, $targetActor)?->local === true,
			'blocking' => $this->blocks?->findByActor($viewerId, $targetActor) !== null,
			'blocked_by' => false,
			'muting' => $mute !== null,
			'muting_notifications' => $mute?->notifications ?? false,
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
	 * The accounts following one local account, for the public profile view.
	 *
	 * A local follower is stored as its absolute actor URL; the profile list
	 * presents it with the same stable local id as every other account shape.
	 *
	 * @return list<string> local user ids and remote actor URLs, newest first
	 */
	public function followerAccountIds(string $userId): array
	{
		$userId = $this->known($userId);

		return array_map(
			fn (string $actorId): string => $this->accountIdForActor($actorId),
			$this->followers->actorIds($userId),
		);
	}

	/**
	 * The accounts one local account follows, for the public profile view.
	 *
	 * Remote follows live in the outgoing graph, local-to-local follows in the
	 * marked follower graph. Both carry a follow time, so the merged page keeps
	 * one newest-first order instead of concatenating two lists.
	 *
	 * @return list<string> local user ids and remote actor URLs, newest first
	 */
	public function followingAccountIds(string $userId): array
	{
		$userId = $this->known($userId);
		$edges = [];

		foreach ($this->following->followedActors($userId) as $followed) {
			$edges[] = ['id' => $followed->actorId, 'at' => $followed->followedAt, 'key' => $followed->id];
		}

		foreach ($this->followers->localFollowsByActor($this->activities->actorId($userId)) as $follower) {
			$edges[] = ['id' => $follower->localUserId, 'at' => $follower->followedAt, 'key' => $follower->id];
		}

		usort($edges, static function (array $a, array $b): int {
			$byTime = $b['at'] <=> $a['at'];

			return $byTime !== 0 ? $byTime : strcmp((string) $b['key'], (string) $a['key']);
		});

		$ids = [];
		foreach ($edges as $edge) {
			$id = (string) $edge['id'];
			// Keeping the id as the value also keeps numeric ids strings:
			// array_keys() would turn "123" into an int key.
			$ids[$id] = $id;
		}

		return array_values($ids);
	}

	/**
	 * Maps a local actor URL back to the local account id, leaving remote actors
	 * untouched. A stale local URL stays a remote-shaped value rather than being
	 * guessed into an account that no longer exists.
	 */
	public function accountIdForActor(string $actorId): string
	{
		$actorId = trim($actorId);
		$prefix = rtrim($this->activities->baseUrl(), '/') . '/@';

		if (str_starts_with($actorId, $prefix)) {
			$candidate = rawurldecode(substr($actorId, strlen($prefix)));
			if ($candidate !== '' && $this->actors->has($candidate)) {
				return $candidate;
			}
		}

		return $actorId;
	}

	/**
	 * The local accounts one account follows, for the home timeline.
	 *
	 * Local accounts the reader blocks are omitted without removing the edge;
	 * unblocking makes the follow visible again.
	 *
	 * @return list<string>
	 */
	/**
	 * Local accounts the viewer blocks, with the actor URL mapped back to the
	 * stable local id. Remote blocks stay out of this local read model.
	 *
	 * @return list<string>
	 */
	public function blockedAccountIds(string $userId): array
	{
		if ($this->blocks === null) {
			return [];
		}

		$ids = [];
		foreach ($this->blocks->actorIds($userId) as $actorId) {
			$accountId = $this->accountIdForActor($actorId);
			if ($this->actors->has($accountId)) {
				$ids[$accountId] = $accountId;
			}
		}

		return array_values($ids);
	}

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
	 * Whether an id belongs to a local account the host directory knows.
	 * Direct-post recipients use this check before a post is stored.
	 */
	public function hasLocalActor(string $userId): bool
	{
		$userId = trim($userId);

		return $userId !== '' && $this->actors->has($userId);
	}

	/**
	 * Whether $followerId follows $targetId through a local follower edge.
	 *
	 * The check deliberately needs no directory lookup: both ids are local
	 * account ids and the edge stores the derived actor URL. A stale or
	 * deleted target simply has no edge and answers false.
	 */
	public function isLocalFollower(string $followerId, string $targetId): bool
	{
		$followerId = trim($followerId);
		$targetId = trim($targetId);
		if ($followerId === '' || $targetId === '') {
			return false;
		}

		return $this->followers->findByActor(
			$targetId,
			$this->activities->actorId($followerId),
		)?->local === true;
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

	/**
	 * Local accounts who currently follow one local account.
	 *
	 * The event stores this fan-out snapshot; the broker still checks current
	 * account, block, and mute state before delivering each event.
	 *
	 * @return list<string>
	 */
	public function localFollowerIds(string $userId): array
	{
		$userId = $this->known($userId);

		return $this->followers->localUserIdsForActor($this->activities->actorId($userId));
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
