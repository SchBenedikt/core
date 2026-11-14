<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

/**
 * A fixture actor directory for tests, tools and the demo.
 *
 * It mirrors the HTTP fetcher's contract: an unknown actor is an
 * UnknownActorException, never an empty document.
 */
final class InMemoryRemoteActorFetcher implements RemoteActorFetcher
{
	/**
	 * @param array<string, RemoteActor> $actors
	 */
	public function __construct(
		private array $actors = [],
	) {
	}

	public function with(RemoteActor $actor): self
	{
		$copy = clone $this;
		$copy->actors[$actor->actorId] = $actor;

		return $copy;
	}

	public function fetch(string $actorId): RemoteActor
	{
		$actor = $this->actors[$actorId] ?? null;
		if ($actor === null) {
			throw new UnknownActorException($actorId);
		}

		return $actor;
	}
}
