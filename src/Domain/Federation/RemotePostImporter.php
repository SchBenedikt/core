<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Port for turning a remote object id into a stored remote post on demand.
 *
 * Reply and interaction paths resolve their target through the local store
 * first; when the store does not know the object, the host may fetch it from
 * its origin and store it the same way an inbound delivery would have. The
 * implementation decides which audiences are safe to cache — the caller
 * still applies the read rule before acting on the result, so a permissive
 * cache can never widen who may interact.
 */
interface RemotePostImporter
{
	/**
	 * Returns the stored remote post for `$objectId`, importing it from its
	 * origin first when necessary. Null when the object cannot be fetched or
	 * proven storable.
	 */
	public function ensure(string $objectId): ?RemotePost;
}
