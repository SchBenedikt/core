<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * The default policy: every verified Follow is accepted.
 *
 * A host that needs approval or an allowlist supplies its own policy instead.
 */
final class AcceptAllFollows implements FollowPolicy
{
	public function accepts(RemoteActor $actor, string $localUserId): bool
	{
		return true;
	}
}
