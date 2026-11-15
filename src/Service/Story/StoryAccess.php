<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Story;

use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

/**
 * The only authorization rule for stories: owner or an accepted follower.
 *
 * For a local story the viewer must be in the owner's incoming follower
 * graph; for a remote story the local viewer must have an accepted outgoing
 * follow of the owner. Expiry and deletion are checked here as well, so a
 * stale repository read cannot expose an old object through any route.
 */
final class StoryAccess
{
	public function __construct(
		private readonly FollowerRepository $followers,
		private readonly FollowingRepository $following,
		private readonly ActivityFactory $activities,
		private readonly Clock $clock,
		private readonly ?ModerationPolicy $moderation = null,
	) {
	}

	public function canView(?string $viewerId, Story $story): bool
	{
		if ($viewerId === null || $viewerId === '') {
			return false;
		}

		if ($story->isDeleted() || $story->isExpiredAt($this->clock->now())) {
			return false;
		}

		if ($this->moderation?->allowsActor($story->ownerId) === false) {
			return false;
		}

		if ($story->ownerId === $viewerId) {
			return true;
		}

		if ($story->local) {
			$follower = $this->followers->findByActor($story->ownerId, $this->activities->actorId($viewerId));

			return $follower?->state === FollowState::Accepted;
		}

		return $this->following->find($viewerId, $story->ownerId)?->state === FollowState::Accepted;
	}
}
