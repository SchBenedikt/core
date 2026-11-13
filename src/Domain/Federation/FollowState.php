<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * The lifecycle of an outgoing follow.
 *
 * A new Follow starts pending. Only a signed Accept or Reject from the target
 * actor may move it to accepted or rejected. An Unfollow removes the row
 * instead of adding a fourth state, so a late response can never resurrect an
 * edge that the local account already cancelled.
 */
enum FollowState: string
{
	case Pending = 'pending';

	case Accepted = 'accepted';

	case Rejected = 'rejected';

	/**
	 * Existing rows from before the state column are accepted follows.
	 */
	public static function fromStored(?string $value): self
	{
		if ($value === null || $value === '') {
			return self::Accepted;
		}

		// An unknown stored value is not evidence of an accepted edge.
		return self::tryFrom($value) ?? self::Pending;
	}

	public function isPending(): bool
	{
		return $this === self::Pending;
	}

	public function isAccepted(): bool
	{
		return $this === self::Accepted;
	}
}
