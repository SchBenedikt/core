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
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;

use function is_string;
use function rtrim;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * Applies the federation target guard before delegating a key lookup.
 *
 * A host may supply its own ActorResolver. The guard belongs to the core's
 * inbound security boundary, so it must not be bypassed just because the host
 * chose a custom resolver: the key id is an actor URL and is checked before
 * that resolver can fetch it.
 */
final class GuardedActorResolver implements ActorResolver
{
	public function __construct(
		private readonly ActorResolver $inner,
		private readonly FederationTargetGuard $guard,
		private readonly ?string $localBaseUrl = null,
	) {
	}

	public function resolveByKeyId(string $keyId): RemoteActor
	{
		$fragment = strpos($keyId, '#');
		$actorId = $fragment === false ? $keyId : substr($keyId, 0, $fragment);

		// A local actor is not an outbound destination. This also keeps a
		// loopback development instance able to receive its own signed test
		// activities without weakening remote URL checks.
		$local = $this->localBaseUrl === null
			? null
			: rtrim($this->localBaseUrl, '/');
		if (is_string($local) && $local !== '' && str_starts_with($actorId, $local . '/')) {
			return $this->inner->resolveByKeyId($keyId);
		}

		try {
			$this->guard->addressesFor($actorId);
		} catch (RemoteFetchException) {
			throw new UnknownActorException($keyId);
		}

		return $this->inner->resolveByKeyId($keyId);
	}
}
