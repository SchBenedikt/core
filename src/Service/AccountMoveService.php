<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\AccountMove;
use AlohaSocial\Core\Domain\Federation\AccountMoveDirection;
use AlohaSocial\Core\Domain\Federation\AccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\AccountMoveState;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;

use function array_unique;
use function array_values;
use function count;
use function filter_var;
use function in_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function strtolower;
use function trim;

/**
 * Verifies and applies ActivityPub account moves.
 *
 * A Move is only accepted when the signer is the origin actor, the origin
 * document names the target in `alsoKnownAs`, and the fetched target document
 * names the origin back. Both documents therefore make the same claim; a
 * spoofed Move cannot redirect followers just by signing the activity.
 *
 * Migration is split into bounded batches recorded on the move row. One batch
 * queues the next worker job, so an interruption resumes from the processed
 * ids instead of duplicating or losing follows. Keys, post ids and actor URLs
 * are never rewritten: this moves follow relationships, not an identity.
 */
final class AccountMoveService
{
	/** Edges handled by one worker job before it queues a continuation. */
	public const BATCH_SIZE = 20;

	/** How many rows one scan page reads while looking for unprocessed edges. */
	public const SCAN_PAGE = 100;

	public function __construct(
		private readonly AccountMoveRepository $moves,
		private readonly FollowingRepository $following,
		private readonly FollowerRepository $followers,
		private readonly FollowingService $followingService,
		private readonly RemoteActorFetcher $remoteActors,
		private readonly ActivityFactory $activities,
		private readonly JobQueue $queue,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Records a signed inbound Move after both actor documents agree.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @throws AccountMoveException with a stable reason when the move is refused
	 */
	public function receive(RemoteActor $signer, array $activity): AccountMove
	{
		$object = $activity['object'] ?? null;
		$target = $activity['target'] ?? null;
		$eventId = $activity['id'] ?? null;

		if (!is_string($object) || !is_string($target) || !is_string($eventId)) {
			throw new AccountMoveException('invalid_activity', 'A Move needs an object and target actor.');
		}

		$eventId = trim($eventId);
		$object = trim($object);
		$target = trim($target);

		if ($eventId === '' || mb_strlen($eventId) > AccountMove::MAX_ID_LENGTH) {
			throw new AccountMoveException('invalid_activity', 'The Move id is missing or too long.');
		}

		if ($object !== $signer->actorId) {
			throw new AccountMoveException(
				'actor_mismatch',
				'A Move must name the signing actor as its object.',
			);
		}

		if (!$this->isSecureUrl($target) || $target === $object) {
			throw new AccountMoveException('invalid_target', 'A Move needs a distinct https actor target.');
		}

		$targetActor = $this->fetchTarget($target);

		if (!$this->hasAlias($signer->alsoKnownAs, $target)) {
			throw new AccountMoveException(
				'move_not_reciprocated',
				'The origin actor does not advertise the target in alsoKnownAs.',
			);
		}

		if (!$this->hasAlias($targetActor->alsoKnownAs, $signer->actorId)) {
			throw new AccountMoveException(
				'move_not_reciprocated',
				'The target actor does not advertise the origin in alsoKnownAs.',
			);
		}

		if ($targetActor->movedTo !== null) {
			throw new AccountMoveException(
				'move_target_moved',
				'The target actor has itself moved; follow the final destination instead.',
			);
		}

		$existing = $this->moves->find($eventId);
		if ($existing !== null) {
			// A retry after a queue failure finds the pending row; queue the
			// continuation again. The worker is idempotent through the
			// processed ids, so a duplicate job cannot duplicate an edge.
			if (!$existing->isCompleted()) {
				$this->queue->push(JobType::ACCOUNT_MOVE, ['event_id' => $eventId]);
			}

			return $existing;
		}

		$at = Time::toSecondPrecision($this->clock->now());
		$move = new AccountMove(
			$eventId,
			$signer->actorId,
			$target,
			$targetActor->inboxUrl,
			AccountMoveDirection::Inbound,
			AccountMoveState::Pending,
			[],
			[],
			$at,
			$at,
		);

		$this->moves->insert($move);
		$this->queue->push(JobType::ACCOUNT_MOVE, ['event_id' => $eventId]);

		return $move;
	}

	/**
	 * Starts an outgoing move for a local account.
	 *
	 * The target must already name the local actor in `alsoKnownAs`; the local
	 * actor document then advertises `movedTo` and the reciprocal alias. The
	 * Move id is derived from both actors, so a retry cannot publish a second
	 * move and existing object URLs stay untouched.
	 *
	 * @throws AccountMoveException when the target does not reciprocate
	 */
	public function moveLocalAccount(string $localUserId, string $targetActorId): AccountMove
	{
		$localUserId = trim($localUserId);
		$targetActorId = trim($targetActorId);

		if ($localUserId === '') {
			throw new AccountMoveException('invalid_activity', 'A local move needs a user.');
		}

		if (!$this->isSecureUrl($targetActorId)) {
			throw new AccountMoveException('invalid_target', 'A local move needs an https target actor.');
		}

		$origin = $this->activities->actorId($localUserId);
		if ($targetActorId === $origin) {
			throw new AccountMoveException('invalid_target', 'An account cannot move to itself.');
		}

		$targetActor = $this->fetchTarget($targetActorId);
		if (!$this->hasAlias($targetActor->alsoKnownAs, $origin)) {
			throw new AccountMoveException(
				'move_not_reciprocated',
				'The target actor does not advertise this account in alsoKnownAs.',
			);
		}

		if ($targetActor->movedTo !== null) {
			throw new AccountMoveException(
				'move_target_moved',
				'The target actor has itself moved; choose the final destination.',
			);
		}

		$eventId = $this->activities->moveId($origin, $targetActorId);
		$existing = $this->moves->find($eventId);
		if ($existing !== null) {
			return $existing;
		}

		$at = Time::toSecondPrecision($this->clock->now());
		$move = new AccountMove(
			$eventId,
			$origin,
			$targetActorId,
			$targetActor->inboxUrl,
			AccountMoveDirection::Outbound,
			AccountMoveState::Completed,
			[],
			[],
			$at,
			$at,
		);
		$this->moves->insert($move);

		$activity = $this->activities->move($origin, $targetActorId);
		foreach (array_values(array_unique($this->followers->inboxesFor($localUserId))) as $inbox) {
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $inbox,
				'activity' => $activity,
				'key_id' => $this->activities->keyId($localUserId),
			], subject: JobSubject::account($localUserId));
		}

		return $move;
	}

	/**
	 * Processes one bounded batch and queues a continuation while work remains.
	 *
	 * @throws AccountMoveNotFoundException
	 */
	public function continue(string $eventId): AccountMove
	{
		$move = $this->moves->find($eventId);
		if ($move === null) {
			throw new AccountMoveNotFoundException('The account move does not exist: ' . $eventId);
		}

		if ($move->isCompleted()) {
			return $move;
		}

		$processed = 0;

		foreach ($this->unprocessedOutgoing($move) as $edge) {
			$this->migrateOutgoing($move, $edge);
			$move = $move->withOutgoingProcessed($edge->id, Time::toSecondPrecision($this->clock->now()));
			$this->moves->save($move);
			$processed++;

			if ($processed >= self::BATCH_SIZE) {
				$this->queue->push(JobType::ACCOUNT_MOVE, ['event_id' => $move->eventId]);

				return $move;
			}
		}

		foreach ($this->unprocessedFollowers($move) as $follower) {
			$this->migrateFollower($move, $follower);
			$move = $move->withFollowerProcessed($follower->id, Time::toSecondPrecision($this->clock->now()));
			$this->moves->save($move);
			$processed++;

			if ($processed >= self::BATCH_SIZE) {
				$this->queue->push(JobType::ACCOUNT_MOVE, ['event_id' => $move->eventId]);

				return $move;
			}
		}

		$move = $move->completed(Time::toSecondPrecision($this->clock->now()));
		$this->moves->save($move);

		return $move;
	}

	public function find(string $eventId): ?AccountMove
	{
		return $this->moves->find($eventId);
	}

	public function findOutboundByOrigin(string $originActorId): ?AccountMove
	{
		return $this->moves->findOutboundByOrigin($originActorId);
	}

	/**
	 * @return list<\AlohaSocial\Core\Domain\Federation\FollowedActor>
	 */
	private function unprocessedOutgoing(AccountMove $move): array
	{
		$result = [];
		$offset = 0;

		do {
			$page = $this->following->followedActorsForActor($move->originActorId, self::SCAN_PAGE, $offset);
			foreach ($page as $edge) {
				if (in_array($edge->id, $move->processedOutgoingIds, true)) {
					continue;
				}

				$result[] = $edge;
				if (count($result) >= self::BATCH_SIZE) {
					return $result;
				}
			}

			$offset += self::SCAN_PAGE;
		} while (count($page) === self::SCAN_PAGE);

		return $result;
	}

	/**
	 * @return list<\AlohaSocial\Core\Domain\Federation\Follower>
	 */
	private function unprocessedFollowers(AccountMove $move): array
	{
		$result = [];
		$offset = 0;

		do {
			$page = $this->followers->followersForActor($move->originActorId, self::SCAN_PAGE, $offset);
			foreach ($page as $follower) {
				if (in_array($follower->id, $move->processedFollowerIds, true)) {
					continue;
				}

				$result[] = $follower;
				if (count($result) >= self::BATCH_SIZE) {
					return $result;
				}
			}

			$offset += self::SCAN_PAGE;
		} while (count($page) === self::SCAN_PAGE);

		return $result;
	}

	private function migrateOutgoing(AccountMove $move, \AlohaSocial\Core\Domain\Federation\FollowedActor $edge): void
	{
		$target = $this->following->find($edge->localUserId, $move->targetActorId);

		if ($target === null) {
			$result = $this->followingService->follow(
				$edge->localUserId,
				$move->targetActorId,
				$move->targetInboxUrl,
			);
			$target = $result->followed;
		}

		// An accepted follow stays accepted after a verified move; a pending
		// request stays pending and still needs the target's signed Accept.
		if ($edge->state === FollowState::Accepted && $target->state === FollowState::Pending) {
			$this->following->updateState($target->id, FollowState::Pending, FollowState::Accepted);
		}

		$this->followingService->unfollowById($edge->localUserId, $edge->id);
	}

	private function migrateFollower(AccountMove $move, \AlohaSocial\Core\Domain\Federation\Follower $follower): void
	{
		// A pending request from the old identity is not silently accepted on
		// behalf of the new one; the target must send its own Follow.
		if ($follower->state === FollowState::Pending) {
			$this->followers->remove($follower->id, $follower->localUserId);

			return;
		}

		$existing = $this->followers->findByActor($follower->localUserId, $move->targetActorId);
		if ($existing !== null) {
			if ($existing->state === FollowState::Pending) {
				$this->followers->updateState(
					$existing->id,
					$follower->localUserId,
					FollowState::Pending,
					FollowState::Accepted,
				);
			}

			$this->followers->remove($follower->id, $follower->localUserId);

			return;
		}

		$this->followers->replaceActor(
			$follower->id,
			$follower->localUserId,
			$follower->actorId,
			$move->targetActorId,
			$move->targetInboxUrl,
			null,
		);
	}

	private function fetchTarget(string $targetActorId): RemoteActor
	{
		try {
			return $this->remoteActors->fetch($targetActorId);
		} catch (UnknownActorException) {
			throw new AccountMoveException(
				'move_target_unknown',
				'The target actor document could not be verified.',
			);
		}
	}

	/**
	 * @param list<string> $aliases
	 */
	private function hasAlias(array $aliases, string $actorId): bool
	{
		foreach ($aliases as $alias) {
			if (rtrim($alias, '/') === rtrim($actorId, '/')) {
				return true;
			}
		}

		return false;
	}

	private function isSecureUrl(string $value): bool
	{
		if ($value === '' || trim($value) !== $value || mb_strlen($value) > AccountMove::MAX_URL_LENGTH) {
			return false;
		}

		if (filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);
		$host = parse_url($value, PHP_URL_HOST);

		return strtolower((string) $scheme) === 'https' && is_string($host) && $host !== '';
	}
}
