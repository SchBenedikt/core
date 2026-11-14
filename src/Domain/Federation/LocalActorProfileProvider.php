<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Host-owned public profile data for one local account.
 *
 * The host keeps editing, credentials and private metadata. The core only
 * reads this projection when it serializes an actor document, publishes a
 * profile Update or maps the account to Mastodon's account shape.
 *
 * A null return means "no extended profile"; the actor keeps its historical
 * minimal document instead of failing. Unknown accounts are still rejected by
 * {@see LocalActorDirectory}.
 */
interface LocalActorProfileProvider
{
	public function profile(string $userId): ?LocalActorProfile;
}
