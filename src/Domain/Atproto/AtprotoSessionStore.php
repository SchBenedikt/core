<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Host-owned storage for an AT Protocol OAuth session.
 *
 * The host stores access and refresh tokens in its secret store; the core does
 * not ship a credential table. `save()` replaces the previous session after a
 * successful rotation, so a crash cannot leave the old refresh token active in
 * the core's view.
 */
interface AtprotoSessionStore
{
	public function find(string $localUserId): ?AtprotoOAuthSession;

	public function save(string $localUserId, AtprotoOAuthSession $session): void;
}
