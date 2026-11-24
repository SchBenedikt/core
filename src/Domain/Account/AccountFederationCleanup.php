<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Account;

use AlohaSocial\Core\Domain\Federation\FollowedActor;

/** Remote destinations to notify before an account's graph is erased. */
final readonly class AccountFederationCleanup
{
	/**
	 * @param list<string> $followerInboxes
	 * @param list<FollowedActor> $following
	 */
	public function __construct(
		public array $followerInboxes,
		public array $following,
	) {
	}
}
