<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

/**
 * Exact remote actor lookup by handle.
 *
 * This is deliberately not a remote-object search: only a handle triggers a
 * network request, and the request goes through WebFinger to the host named
 * in that handle. Hosts can replace this port with an opt-in adapter for a
 * remote search service without changing the neutral search contract.
 */
interface RemoteActorSearch
{
	/**
	 * @throws RemoteSearchException when the handle is valid but lookup fails
	 */
	public function lookup(RemoteHandle $handle): ?RemoteActorProfile;
}
