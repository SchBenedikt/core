<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;

/**
 * An actor resolver that fetches from addresses the caller already validated.
 *
 * A guard that resolves a hostname and a resolver that resolves it again is a
 * gap, not a check: the addresses the guard approved are not the ones the
 * connection uses, which is exactly the window a DNS-rebinding peer needs.
 * {@see GuardedActorResolver} therefore hands its validated addresses to this
 * method instead of asking the resolver to look the name up a second time.
 */
interface PinnedActorResolver extends ActorResolver
{
	/**
	 * Resolves a key id using connection-pinned addresses.
	 *
	 * @param list<string> $addresses public addresses the caller validated and
	 *                                wants the connection pinned to; an empty
	 *                                list means the caller deliberately
	 *                                skipped the check (a local actor id) and
	 *                                only the bounded transfer applies
	 *
	 * @throws UnknownActorException when the document cannot be fetched or does not confirm the key id
	 */
	public function resolveByKeyIdAtAddresses(string $keyId, array $addresses): RemoteActor;
}
