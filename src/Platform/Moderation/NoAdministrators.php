<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Moderation;

/**
 * The fail-closed default: no local user is a moderator.
 */
final class NoAdministrators implements AdminAuthorization
{
	public function isModerator(string $userId): bool
	{
		return false;
	}
}
