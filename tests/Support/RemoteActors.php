<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Federation\InMemoryRemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\RemoteActor;

/**
 * Fixture remote actors for tests that need a verified actor document,
 * for example the follow endpoint's server-side inbox resolution.
 */
final class RemoteActors
{
	public static function actor(
		string $actorId,
		string $inboxUrl,
		?string $sharedInboxUrl = null,
		?string $keyId = null,
		?string $movedTo = null,
	): RemoteActor {
		return new RemoteActor(
			actorId: $actorId,
			keyId: $keyId ?? $actorId . '#main-key',
			inboxUrl: $inboxUrl,
			publicKeyPem: "-----BEGIN PUBLIC KEY-----\nfixture\n-----END PUBLIC KEY-----\n",
			sharedInboxUrl: $sharedInboxUrl,
			movedTo: $movedTo,
		);
	}

	/**
	 * @param RemoteActor ...$actors
	 */
	public static function fetcher(RemoteActor ...$actors): InMemoryRemoteActorFetcher
	{
		$map = [];
		foreach ($actors as $actor) {
			$map[$actor->actorId] = $actor;
		}

		return new InMemoryRemoteActorFetcher($map);
	}
}
