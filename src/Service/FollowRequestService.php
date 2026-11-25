<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

use function trim;

/**
 * The owner's inbox for locked-account follow requests.
 *
 * A locked account stores an inbound Follow as a pending follower row. The
 * owner can accept it, which queues the signed Accept to the follower's
 * individual inbox, or reject it, which queues a Reject and removes the
 * request. Both transitions are conditional on the row still being pending,
 * so a double click cannot send two responses.
 */
final class FollowRequestService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 100;

	public function __construct(
		private readonly FollowerRepository $followers,
		private readonly FollowerService $followerService,
		private readonly ActivityFactory $activities,
		private readonly JobQueue $queue,
	) {
	}

	/**
	 * @return list<Follower>
	 *
	 * @throws InvalidCursorException
	 */
	public function pending(
		string $localUserId,
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
	): array {
		$localUserId = trim($localUserId);
		if ($localUserId === '') {
			return [];
		}

		return $this->followerService->pending($localUserId, $limit, $before);
	}

	/**
	 * Mastodon exposes account ids as follow-request cursors. The internal row
	 * id breaks ties when requests share a timestamp. A stale cursor produces
	 * an empty page, which is safe to retry.
	 *
	 * @return list<Follower>
	 */
	public function pendingBetweenActors(
		string $localUserId,
		int $limit,
		?string $maxActorId,
		?string $sinceActorId,
	): array
	{
		$localUserId = trim($localUserId);
		if ($localUserId === '') {
			return [];
		}

		$maxCursor = $this->cursorActor($localUserId, $maxActorId);
		$sinceCursor = $this->cursorActor($localUserId, $sinceActorId);
		if (($maxActorId !== null && $maxCursor === null)
			|| ($sinceActorId !== null && $sinceCursor === null)
		) {
			return [];
		}

		return $this->followerService->pending(
			$localUserId,
			$limit,
			$maxCursor?->followedAt->format('Y-m-d\\TH:i:s\\Z'),
			$maxCursor?->id,
			$sinceCursor?->followedAt->format('Y-m-d\\TH:i:s\\Z'),
			$sinceCursor?->id,
		);
	}

	private function cursorActor(string $localUserId, ?string $actorId): ?Follower
	{
		if ($actorId === null || trim($actorId) === '') {
			return null;
		}

		$follower = $this->followers->findByActor($localUserId, trim($actorId));

		return $follower !== null && $follower->isPending() ? $follower : null;
	}

	public function isAcceptedFollower(string $localUserId, string $actorId): bool
	{
		$follower = $this->followers->findByActor(trim($localUserId), trim($actorId));

		return $follower !== null && $follower->state === FollowState::Accepted;
	}

	/**
	 * @throws FollowRequestNotFoundException
	 */
	public function accept(string $localUserId, string $requestId): Follower
	{
		return $this->respond($localUserId, $requestId, true);
	}

	/**
	 * @throws FollowRequestNotFoundException
	 */
	public function reject(string $localUserId, string $requestId): Follower
	{
		return $this->respond($localUserId, $requestId, false);
	}

	/** @throws FollowRequestNotFoundException */
	public function acceptActor(string $localUserId, string $actorId): Follower
	{
		return $this->respondToActor($localUserId, $actorId, true);
	}

	/** @throws FollowRequestNotFoundException */
	public function rejectActor(string $localUserId, string $actorId): Follower
	{
		return $this->respondToActor($localUserId, $actorId, false);
	}

	private function respondToActor(string $localUserId, string $actorId, bool $accept): Follower
	{
		$follower = $this->followers->findByActor(trim($localUserId), trim($actorId));
		if ($follower === null || !$follower->isPending()) {
			throw new FollowRequestNotFoundException('Follow request not found.');
		}

		return $this->respond($localUserId, $follower->id, $accept);
	}

	/**
	 * @throws FollowRequestNotFoundException
	 */
	private function respond(string $localUserId, string $requestId, bool $accept): Follower
	{
		$localUserId = trim($localUserId);
		$requestId = trim($requestId);
		$follower = $requestId === '' ? null : $this->followers->find($requestId);

		if ($follower === null
			|| $follower->localUserId !== $localUserId
			|| !$follower->isPending()
		) {
			throw new FollowRequestNotFoundException('Follow request not found.');
		}

		$follow = $follower->followActivity;
		if ($follow === []) {
			// Older pending rows cannot be answered with a valid embedded
			// Follow. Refusing is safer than inventing one for the peer.
			throw new FollowRequestNotFoundException('Follow request has no original activity.');
		}

		$to = $accept ? FollowState::Accepted : FollowState::Rejected;
		// Claim the transition before queueing: two racing requests must not
		// both send a signed answer for the same pending row.
		if (!$this->followers->updateState($follower->id, $localUserId, FollowState::Pending, $to)) {
			throw new FollowRequestNotFoundException('Follow request was already answered.');
		}

		$activity = $accept
			? $this->activities->acceptFollow($localUserId, $follower->id, $follow)
			: $this->activities->rejectFollow($localUserId, $follower->id, $follow);

		$this->queue->push(JobType::FEDERATION_DELIVER, [
			'inbox' => $follower->inboxUrl,
			'activity' => $activity,
			'key_id' => $this->activities->keyId($localUserId),
		], subject: JobSubject::account($localUserId));

		return $accept ? $follower->withState(FollowState::Accepted) : $follower;
	}
}
