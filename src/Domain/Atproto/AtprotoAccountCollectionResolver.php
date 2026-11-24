<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Optional capability for hosts that link more than one AT Protocol identity
 * to a local user. The core only uses a selected identity when its DID is
 * supplied by the caller; it never fans a user action out to every account.
 */
interface AtprotoAccountCollectionResolver extends AtprotoAccountResolver
{
	/** @return list<AtprotoAccount> active identities controlled by the user */
	public function resolveAll(string $localUserId): array;
}
