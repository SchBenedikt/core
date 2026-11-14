<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Moderation;

/**
 * Host-owned answer to "is this local user an instance moderator?".
 *
 * The core never infers an administrator from an account id, a config flag or
 * a client-supplied role. A host that has no moderation UI can keep the
 * default, which refuses every action.
 */
interface AdminAuthorization
{
	public function isModerator(string $userId): bool;
}
