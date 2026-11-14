<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

/**
 * The default policy for a host that has not provisioned moderation storage.
 */
final class AllowsAllModeration implements ModerationPolicy
{
	public function allowsActor(string $actorId): bool
	{
		return true;
	}

	public function allowsDomain(string $domain): bool
	{
		return true;
	}
}
