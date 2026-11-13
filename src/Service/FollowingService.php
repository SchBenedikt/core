<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Db\TransactionRunner;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use Throwable;

/**
 * The outgoing follow graph as a use case.
 *
 * A new Follow is stored as pending and queued. The queue retries a failed
 * delivery without losing the local request. Only a verified Accept/Reject
 * from the target actor may change the state, and the transition is
 * conditional on the row still being pending. Unfollow removes the row
 * instead of adding another state, so a response that arrives after it can
 * never bring the edge back.
 *
 * A direct delivery path remains for a caller that has no queue. Production
 * assemblies always pass the durable queue.
 */
final class FollowingService
{
	/** Matches the indexed column in {@see \AlohaSocial\Core\Domain\Federation\FollowingSchema}. */
	public const MAX_ACTOR_LENGTH = 255;

	public const MAX_INBOX_LENGTH = 512;

	public function __construct(
		private readonly FollowingRepository $following,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ActivityFactory $activities,
		private readonly ?FederationDeliveryHandler $delivery,
		private readonly ?JobQueue $queue = null,
		private readonly ?TransactionRunner $transactions = null,
	) {
	}

	public function follow(string $localUserId, string $actorId, string $inboxUrl): FollowingResult
	{
		$localUserId = trim($localUserId);
		$actorId = trim($actorId);
		$inboxUrl = trim($inboxUrl);

		$errors = [];

		if ($localUserId === '') {
			$errors['user'] = 'A followed actor needs a local user.';
		}

		if (!$this->isAbsoluteHttpUrl($actorId)) {
			$errors['actor'] = 'actor must be an absolute http(s) URL.';
		} elseif (mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			$errors['actor'] = 'actor may be at most ' . self::MAX_ACTOR_LENGTH . ' characters long.';
		}

		if (!$this->isAbsoluteHttpUrl($inboxUrl)) {
			$errors['inbox'] = 'inbox must be an absolute http(s) URL.';
		} elseif (mb_strlen($inboxUrl) > self::MAX_INBOX_LENGTH) {
			$errors['inbox'] = 'inbox may be at most ' . self::MAX_INBOX_LENGTH . ' characters long.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$existing = $this->following->find($localUserId, $actorId);
		if ($existing !== null && $existing->state !== FollowState::Rejected) {
			if ($existing->inboxUrl !== $inboxUrl) {
				$existing = $existing->withInbox($inboxUrl);
				$this->following->updateInbox($existing);
			}

			return new FollowingResult($existing, false);
		}

		if ($this->delivery === null) {
			throw new FollowingNotConfiguredException();
		}

		// A rejected request is a new attempt: remove the old row first so a
		// late Reject for the old Follow id cannot match the new attempt.
		if ($existing !== null) {
			$this->following->remove($localUserId, $actorId);
		}

		$id = $this->ids->generate();
		$followed = new FollowedActor(
			id: $id,
			localUserId: $localUserId,
			actorId: $actorId,
			inboxUrl: $inboxUrl,
			followedAt: Time::toSecondPrecision($this->clock->now()),
			state: FollowState::Pending,
			followActivityId: $this->activities->followId($id),
		);

		try {
			$this->atomically(function () use ($localUserId, $followed): void {
				$this->following->insert($followed);
				$this->enqueueFollow($localUserId, $followed);
			});
		} catch (DatabaseException $exception) {
			$stored = $this->following->find($localUserId, $actorId);

			// Two requests for the same pair raced. The unique index chose
			// the other row; return it instead of surfacing a storage failure.
			if ($stored !== null && $stored->id !== $followed->id) {
				return new FollowingResult($stored, false);
			}

			// Without a transaction boundary the insert can have succeeded
			// before the queue push failed. Remove that orphan before retrying.
			if ($this->transactions === null && $stored?->id === $followed->id) {
				$this->following->remove($localUserId, $actorId);
			}

			throw $exception;
		} catch (Throwable $exception) {
			// Without a transaction boundary (an in-memory assembly), undo
			// the row if the queue push failed so no orphan remains.
			if ($this->transactions === null) {
				$this->following->remove($localUserId, $actorId);
			}

			throw $exception;
		}

		return new FollowingResult($followed, true);
	}

	/**
	 * @return bool whether the edge existed and was removed
	 */
	public function unfollow(string $localUserId, string $actorId): bool
	{
		$localUserId = trim($localUserId);
		$actorId = trim($actorId);

		$followed = $this->following->find($localUserId, $actorId);

		return $followed !== null && $this->unfollowById($localUserId, $followed->id);
	}

	/**
	 * Removes an edge by its local id and queues the matching Undo.
	 *
	 * The row is removed only after the queue accepted the Undo. A late
	 * Accept/Reject then finds no row and is an idempotent no-op.
	 *
	 * @return bool false when the edge is missing or belongs to another account
	 */
	public function unfollowById(string $localUserId, string $id): bool
	{
		$localUserId = trim($localUserId);
		$id = trim($id);

		$followed = $this->following->findById($id);
		if ($followed === null || $followed->localUserId !== $localUserId) {
			return false;
		}

		// A rejected request has no remote edge to undo; forgetting it is
		// already the complete local action.
		if ($followed->state === FollowState::Rejected) {
			return $this->following->remove($localUserId, $followed->actorId);
		}

		if ($this->delivery === null) {
			throw new FollowingNotConfiguredException();
		}

		$undo = $this->activities->undoFollow($localUserId, $followed);

		if ($this->queue === null) {
			$this->deliver($localUserId, $followed, $undo);
			$this->following->remove($localUserId, $followed->actorId);

			return true;
		}

		$this->atomically(function () use ($localUserId, $followed, $undo): void {
			$this->enqueueUndo($localUserId, $followed, $undo);
			$this->following->remove($localUserId, $followed->actorId);
		});

		return true;
	}

	/**
	 * Applies an Accept/Reject to the pending edge named by the exact Follow
	 * activity id. A missing row, a wrong actor and a duplicate response all
	 * leave the store unchanged and return false.
	 */
	public function respondToFollow(string $followActivityId, string $actorId, FollowState $state): bool
	{
		if ($state !== FollowState::Accepted && $state !== FollowState::Rejected) {
			return false;
		}

		$stored = $this->following->findByActivityId($followActivityId);
		if ($stored === null || $stored->actorId !== $actorId) {
			return false;
		}

		return $this->following->updateState($stored->id, FollowState::Pending, $state);
	}

	public function find(string $localUserId, string $actorId): ?FollowedActor
	{
		return $this->following->find($localUserId, $actorId);
	}

	public function findByActivityId(string $followActivityId): ?FollowedActor
	{
		return $this->following->findByActivityId($followActivityId);
	}

	/**
	 * Every accepted followed actor id, newest first, for the public collection.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array
	{
		return $this->following->actorIds($localUserId);
	}

	/**
	 * The accepted remote edges with their follow times, newest first.
	 *
	 * @return list<FollowedActor>
	 */
	public function followedActors(string $localUserId): array
	{
		return $this->following->followedActors($localUserId);
	}

	public function count(string $localUserId): int
	{
		return $this->following->count($localUserId);
	}

	/**
	 * One page of the public following collection, newest first.
	 *
	 * @return list<string>
	 */
	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		return $this->following->actorIdsPage($localUserId, $limit, $offset);
	}

	/**
	 * The local accounts that have an accepted follow of an actor.
	 *
	 * @return list<string>
	 */
	public function localUserIdsForActor(string $actorId): array
	{
		return $this->following->localUserIdsForActor($actorId);
	}

	/**
	 * @template T
	 *
	 * @param callable(): T $work
	 *
	 * @return T
	 */
	private function atomically(callable $work): mixed
	{
		return $this->transactions === null
			? $work()
			: $this->transactions->transaction(static fn (): mixed => $work());
	}

	private function enqueueFollow(string $localUserId, FollowedActor $followed): void
	{
		if ($this->queue !== null) {
			$this->queue->push(JobType::FEDERATION_FOLLOW, [
				'operation' => 'follow',
				'follow_id' => $followed->id,
				'inbox' => $followed->inboxUrl,
				'activity' => $this->activities->followActor($localUserId, $followed),
				'key_id' => $this->activities->keyId($localUserId),
			]);

			return;
		}

		$this->deliver($localUserId, $followed, $this->activities->followActor($localUserId, $followed));
	}

	/**
	 * @param array<string, mixed> $undo
	 */
	private function enqueueUndo(string $localUserId, FollowedActor $followed, array $undo): void
	{
		if ($this->queue !== null) {
			$this->queue->push(JobType::FEDERATION_FOLLOW, [
				'operation' => 'undo',
				'follow_id' => $followed->id,
				'inbox' => $followed->inboxUrl,
				'activity' => $undo,
				'key_id' => $this->activities->keyId($localUserId),
			]);

			return;
		}

		$this->deliver($localUserId, $followed, $undo);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(string $localUserId, FollowedActor $followed, array $activity): void
	{
		if ($this->delivery === null) {
			throw new FollowingNotConfiguredException();
		}

		$this->delivery->deliver(
			$followed->inboxUrl,
			$activity,
			$this->activities->keyId($localUserId),
		);
	}

	/**
	 * Only absolute http(s) URLs are accepted: a bare handle is not an actor
	 * identity, and an inbox that cannot be reached would silently never
	 * receive the Follow or Undo.
	 */
	private function isAbsoluteHttpUrl(string $value): bool
	{
		if ($value === '' || trim($value) !== $value) {
			return false;
		}

		if (filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);
		$host = parse_url($value, PHP_URL_HOST);

		return is_string($scheme)
			&& in_array(strtolower($scheme), ['http', 'https'], true)
			&& is_string($host)
			&& $host !== '';
	}
}
