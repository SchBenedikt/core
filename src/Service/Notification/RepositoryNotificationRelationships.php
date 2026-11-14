<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Notification;

use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Notification\NotificationRelationshipReader;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

use function filter_var;
use function in_array;
use function trim;

/**
 * Reads the two follow directions without depending on the follow services.
 *
 * Local accounts are stored as actor URLs in the follower graph, so the
 * adapter maps a local id through the activity factory exactly like the public
 * relationship view. A remote actor already is that URL.
 */
final readonly class RepositoryNotificationRelationships implements NotificationRelationshipReader
{
	public function __construct(
		private FollowerRepository $followers,
		private FollowingRepository $following,
		private ActivityFactory $activities,
	) {
	}

	public function follows(string $recipientId, string $actorId): bool
	{
		$actorId = trim($actorId);
		if ($actorId === '') {
			return false;
		}

		if (filter_var($actorId, FILTER_VALIDATE_URL) !== false) {
			$edge = $this->following->find($recipientId, $actorId);

			return $edge !== null && $edge->state === FollowState::Accepted;
		}

		return in_array(
			$actorId,
			$this->followers->localUserIdsFollowedBy($this->activities->actorId($recipientId)),
			true,
		);
	}

	public function isFollowedBy(string $recipientId, string $actorId): bool
	{
		$actorId = trim($actorId);
		if ($actorId === '') {
			return false;
		}

		$actorUrl = filter_var($actorId, FILTER_VALIDATE_URL) !== false
			? $actorId
			: $this->activities->actorId($actorId);

		return $this->followers->findByActor($recipientId, $actorUrl) !== null;
	}
}
