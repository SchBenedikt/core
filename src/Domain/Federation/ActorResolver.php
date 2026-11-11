<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * Resolves the actor that owns a signing key.
 *
 * The inbox never trusts a key that arrives with a request: the key id is
 * looked up through this port first, and the resulting actor id is what the
 * activity is checked against. A host backs this with an HTTP fetch of the
 * actor document; tests map key ids to fixture actors.
 */
interface ActorResolver
{
	/**
	 * @throws UnknownActorException when the key id cannot be resolved to a usable actor
	 */
	public function resolveByKeyId(string $keyId): RemoteActor;
}
