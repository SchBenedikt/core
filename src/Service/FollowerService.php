<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\Follower;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The follow graph as a use case.
 *
 * A Follow is idempotent by (local user, actor): repeating it refreshes the
 * inbox and keeps the id, so a peer that retries its Follow request cannot
 * produce duplicate deliveries.
 */
final class FollowerService
{
	/** Matches the indexed column in {@see \AlohaSocial\Core\Domain\Federation\FollowerSchema}. */
	public const MAX_ACTOR_LENGTH = 255;

	public const MAX_INBOX_LENGTH = 512;

	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 100;

	public function __construct(
		private readonly FollowerRepository $followers,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	/**
	 * @param bool $local true for a local-to-local edge, which has no remote delivery
	 */
	public function follow(FollowActor $command, bool $local = false): FollowResult
	{
		$localUserId = trim($command->localUserId);
		$actorId = trim($command->actorId);
		$inboxUrl = trim($command->inboxUrl);
		$sharedInboxUrl = $command->sharedInboxUrl === null ? null : trim($command->sharedInboxUrl);
		if ($sharedInboxUrl === '') {
			$sharedInboxUrl = null;
		}

		$errors = [];

		if ($localUserId === '') {
			$errors['user'] = 'A follower needs a local user.';
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

		if ($sharedInboxUrl !== null) {
			if (!$this->isAbsoluteHttpUrl($sharedInboxUrl)) {
				$errors['shared_inbox'] = 'shared_inbox must be an absolute http(s) URL.';
			} elseif (mb_strlen($sharedInboxUrl) > self::MAX_INBOX_LENGTH) {
				$errors['shared_inbox'] = 'shared_inbox may be at most '
					. self::MAX_INBOX_LENGTH . ' characters long.';
			}
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$existing = $this->followers->findByActor($localUserId, $actorId);
		if ($existing !== null) {
			if ($existing->inboxUrl === $inboxUrl
				&& ($sharedInboxUrl === null || $existing->sharedInboxUrl === $sharedInboxUrl)
			) {
				return new FollowResult($existing, false);
			}

			$updated = $existing->withInbox($inboxUrl, $sharedInboxUrl);
			$this->followers->updateInbox($updated);

			return new FollowResult($updated, false);
		}

		$follower = new Follower(
			id: $this->ids->generate(),
			localUserId: $localUserId,
			actorId: $actorId,
			inboxUrl: $inboxUrl,
			followedAt: Time::toSecondPrecision($this->clock->now()),
			sharedInboxUrl: $sharedInboxUrl,
			local: $local,
		);

		$this->followers->insert($follower);

		return new FollowResult($follower, true);
	}

	/**
	 * @return bool whether the follower existed and belonged to the caller
	 */
	public function unfollow(string $localUserId, string $followerId): bool
	{
		return $this->followers->remove($followerId, $localUserId);
	}

	/**
	 * Removes a local-to-local edge. A missing or remote edge is ignored.
	 *
	 * @return bool whether a local edge was removed
	 */
	public function unfollowLocalByActor(string $localUserId, string $actorId): bool
	{
		$existing = $this->followers->findByActor(trim($localUserId), trim($actorId));
		if ($existing === null || !$existing->local) {
			return false;
		}

		return $this->followers->removeByActor($existing->localUserId, $existing->actorId);
	}

	/**
	 * Removes the edge for an actor, for an inbound Undo(Follow).
	 *
	 * @return bool whether the actor was a follower
	 */
	public function unfollowByActor(string $localUserId, string $actorId): bool
	{
		return $this->followers->removeByActor($localUserId, $actorId);
	}

	/**
	 * @return list<Follower>
	 *
	 * @throws InvalidCursorException
	 */
	public function followers(string $localUserId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		$clamped = max(1, min(self::MAX_LIMIT, $limit));

		return $this->followers->followers($localUserId, $clamped, $this->cursor($before));
	}

	public function count(string $localUserId): int
	{
		return $this->followers->count($localUserId);
	}

	/**
	 * One page of the public followers collection, newest first.
	 *
	 * @return list<string>
	 */
	public function actorIdsPage(string $localUserId, int $limit, int $offset): array
	{
		return $this->followers->actorIdsPage($localUserId, $limit, $offset);
	}

	/**
	 * Every follower actor id, newest first, for the public collection.
	 *
	 * @return list<string>
	 */
	public function actorIds(string $localUserId): array
	{
		return $this->followers->actorIds($localUserId);
	}

	/**
	 * @throws InvalidCursorException
	 */
	private function cursor(?string $before): ?DateTimeImmutable
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		try {
			return Time::parse(trim($before));
		} catch (InvalidArgumentException) {
			throw new InvalidCursorException($before);
		}
	}

	/**
	 * Only absolute http(s) URLs are accepted: a follower without a reachable
	 * inbox would sit in the graph and silently never receive anything.
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
