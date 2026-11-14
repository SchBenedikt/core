<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Fetches and validates a remote actor document by its actor URL.
 *
 * Move verification needs the destination document even though the request
 * was signed with a key from the origin. The implementation applies the same
 * SSRF, size and identity checks as the signing-key resolver, and returns the
 * document's aliases so a reciprocated alsoKnownAs can be checked.
 */
interface RemoteActorFetcher
{
	/**
	 * @throws UnknownActorException when the document is unavailable, unsafe or invalid
	 */
	public function fetch(string $actorId): RemoteActor;
}
