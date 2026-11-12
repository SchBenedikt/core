<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use Throwable;

/**
 * The outgoing follow graph as a use case.
 *
 * A Follow is stored, sent as a signed ActivityPub activity, and rolled back
 * locally if the peer refuses it. An Undo is sent first and the edge is
 * removed only after the peer accepted it; a failed Undo therefore leaves a
 * retryable follow instead of a local-only unfollow.
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
		if ($existing !== null) {
			if ($existing->inboxUrl === $inboxUrl) {
				return new FollowingResult($existing, false);
			}

			$updated = $existing->withInbox($inboxUrl);
			$this->following->updateInbox($updated);

			return new FollowingResult($updated, false);
		}

		if ($this->delivery === null) {
			throw new FollowingNotConfiguredException();
		}

		$followed = new FollowedActor(
			id: $this->ids->generate(),
			localUserId: $localUserId,
			actorId: $actorId,
			inboxUrl: $inboxUrl,
			followedAt: Time::toSecondPrecision($this->clock->now()),
		);

		$this->following->insert($followed);

		try {
			$this->deliver($localUserId, $followed, $this->activities->followActor($localUserId, $followed));
		} catch (Throwable $exception) {
			// The peer did not accept the Follow. Without this rollback the
			// collection would claim a follow the remote side never saw.
			$this->following->remove($localUserId, $actorId);

			throw $exception;
		}

		return new FollowingResult($followed, true);
	}

	/**
	 * @return bool whether the edge existed and the Undo was accepted
	 */
	public function unfollow(string $localUserId, string $actorId): bool
	{
		$localUserId = trim($localUserId);
		$actorId = trim($actorId);

		$followed = $this->following->find($localUserId, $actorId);

		return $followed !== null && $this->unfollowById($localUserId, $followed->id);
	}

	/**
	 * Removes an edge by its local id and sends the matching Undo first.
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

		if ($this->delivery === null) {
			throw new FollowingNotConfiguredException();
		}

		$this->deliver($localUserId, $followed, $this->activities->undoFollow($localUserId, $followed));

		// The edge is removed only after the Undo was accepted. A failed
		// delivery leaves the follow in place and can be retried; forgetting
		// it locally would silently disagree with the peer.
		$this->following->remove($localUserId, $followed->actorId);

		return true;
	}

	/**
	 * Every followed actor id, newest first, for the public collection.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array
	{
		return $this->following->actorIds($localUserId);
	}

	/**
	 * The remote edges with their follow times, newest first.
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
	 * The local accounts that follow an actor; inbound content authorization.
	 *
	 * @return list<string>
	 */
	public function localUserIdsForActor(string $actorId): array
	{
		return $this->following->localUserIdsForActor($actorId);
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
