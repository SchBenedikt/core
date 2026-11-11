<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;

/**
 * Maps key ids to fixture actors, without a network.
 */
final class InMemoryActorResolver implements ActorResolver
{
	/**
	 * @param array<string, RemoteActor> $actors keyed by key id
	 */
	public function __construct(
		private readonly array $actors = [],
	) {
	}

	public function resolveByKeyId(string $keyId): RemoteActor
	{
		return $this->actors[$keyId] ?? throw new UnknownActorException($keyId);
	}
}
