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
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;

/**
 * The outgoing follow graph as a use case.
 *
 * Following is idempotent by (local user, actor): a repeated call returns the
 * existing edge, so the public collection cannot get duplicate entries. This
 * path records the local edge; delivering the ActivityPub `Follow` to the
 * remote actor is a later milestone.
 */
final class FollowingService
{
	/** Matches the indexed column in {@see \AlohaSocial\Core\Domain\Federation\FollowingSchema}. */
	public const MAX_ACTOR_LENGTH = 255;

	public function __construct(
		private readonly FollowingRepository $following,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	public function follow(string $localUserId, string $actorId): FollowedActor
	{
		$localUserId = trim($localUserId);
		$actorId = trim($actorId);

		$errors = [];

		if ($localUserId === '') {
			$errors['user'] = 'A followed actor needs a local user.';
		}

		if (!$this->isAbsoluteHttpUrl($actorId)) {
			$errors['actor'] = 'actor must be an absolute http(s) URL.';
		} elseif (mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			$errors['actor'] = 'actor may be at most ' . self::MAX_ACTOR_LENGTH . ' characters long.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$existing = $this->following->find($localUserId, $actorId);
		if ($existing !== null) {
			return $existing;
		}

		$followed = new FollowedActor(
			id: $this->ids->generate(),
			localUserId: $localUserId,
			actorId: $actorId,
			followedAt: Time::toSecondPrecision($this->clock->now()),
		);

		$this->following->insert($followed);

		return $followed;
	}

	/**
	 * @return bool whether the edge existed
	 */
	public function unfollow(string $localUserId, string $actorId): bool
	{
		return $this->following->remove(trim($localUserId), trim($actorId));
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

	public function count(string $localUserId): int
	{
		return $this->following->count($localUserId);
	}

	/**
	 * Only absolute http(s) URLs are accepted: a bare handle is not an actor
	 * identity, and storing one would poison the collection with fake URLs.
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
