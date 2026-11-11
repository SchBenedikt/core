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

	public function follow(FollowActor $command): FollowResult
	{
		$localUserId = trim($command->localUserId);
		$actorId = trim($command->actorId);
		$inboxUrl = trim($command->inboxUrl);

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

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$existing = $this->followers->findByActor($localUserId, $actorId);
		if ($existing !== null) {
			if ($existing->inboxUrl === $inboxUrl) {
				return new FollowResult($existing, false);
			}

			$updated = $existing->withInbox($inboxUrl);
			$this->followers->updateInbox($updated);

			return new FollowResult($updated, false);
		}

		$follower = new Follower(
			id: $this->ids->generate(),
			localUserId: $localUserId,
			actorId: $actorId,
			inboxUrl: $inboxUrl,
			followedAt: Time::toSecondPrecision($this->clock->now()),
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
