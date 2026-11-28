<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\FollowState;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Post\Visibility;

/**
 * The single per-viewer read rule for a stored remote post.
 *
 * Every surface that exposes remote content — detail, reactions, media,
 * recommendations — must agree on who may see it, so the rule lives here
 * once instead of being re-derived per handler:
 *
 * - a deleted post is readable by nobody;
 * - `public` and `unlisted` content is readable by anyone, signed in or not;
 * - `direct` content is never stored for display and stays refused;
 * - `followers` content and legacy rows with unknown audience require an
 *   accepted follow of the content's author — for a boost that is the
 *   announced object author, not the boosting actor;
 * - a block in either direction hides the content, whatever its audience.
 *
 * Unknown audience fails closed: it is treated like followers-only, never
 * like public.
 */
final class RemotePostAccess
{
	public function __construct(
		private readonly FollowingRepository $following,
		private readonly ?BlockRepository $blocks = null,
		private readonly ?InboundBlockRepository $inboundBlocks = null,
	) {
	}

	public function viewerMayRead(RemotePost $post, ?string $viewerId): bool
	{
		if ($post->deletedAt !== null) {
			return false;
		}

		return $this->audienceAllows(
			$post->audience,
			$post->objectAuthorId ?? $post->actorId,
			$viewerId,
			[$post->actorId],
		);
	}

	/**
	 * The same read rule for stored remote content that is not a post row,
	 * like a Question poll: public and unlisted audiences are open,
	 * followers-only and unknown audiences require an accepted follow of the
	 * author, direct is refused, and a block in either direction hides the
	 * content. `$actorIds` names additional actors whose blocks also apply —
	 * for a boost that is the boosting actor next to the author.
	 *
	 * @param list<string> $actorIds
	 */
	public function audienceAllows(
		?Visibility $audience,
		string $authorId,
		?string $viewerId,
		array $actorIds = [],
	): bool {
		if ($audience === Visibility::Direct) {
			return false;
		}

		if ($viewerId !== null) {
			foreach ([$authorId, ...$actorIds] as $actorId) {
				if ($this->blocks?->findByActor($viewerId, $actorId) !== null
					|| $this->inboundBlocks?->isBlocked($viewerId, $actorId) === true
				) {
					return false;
				}
			}
		}

		if ($audience === Visibility::Public || $audience === Visibility::Unlisted) {
			return true;
		}

		if ($viewerId === null) {
			return false;
		}

		$followed = $this->following->find($viewerId, $authorId);

		return $followed !== null && $followed->state === FollowState::Accepted;
	}
}
