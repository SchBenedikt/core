<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Resolves the AT Protocol account linked to one local user.
 *
 * Account linking, OAuth/app-password sessions and token refresh stay in the
 * host. Returning null means the user has not connected an account; the core
 * will not invent credentials or publish on their behalf.
 */
interface AtprotoAccountResolver
{
	public function resolve(string $localUserId): ?AtprotoAccount;
}
