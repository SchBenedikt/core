<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Platform\Clock\Clock;
use InvalidArgumentException;

/**
 * Caches successfully resolved actors by their full key id.
 *
 * The wrapped resolver still performs every identity check on a miss. This
 * class only remembers the validated result, so a cache hit cannot turn an
 * unconfirmed key into a trusted one. Failures are never cached: a transient
 * network error must not keep an actor unknown for the whole TTL.
 *
 * The TTL is deliberately short by default. A revoked or rotated key remains
 * usable until the entry expires, so the cache trades a bounded revocation
 * delay for avoiding one HTTP round trip per signed request.
 */
final class CachingActorResolver implements ActorResolver
{
	public const DEFAULT_TTL_SECONDS = 300;

	public const DEFAULT_MAX_ENTRIES = 512;

	/**
	 * @var array<string, array{actor: RemoteActor, expires_at: int}>
	 */
	private array $entries = [];

	public function __construct(
		private readonly ActorResolver $inner,
		private readonly Clock $clock,
		private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
		private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
	) {
		if ($ttlSeconds < 1) {
			throw new InvalidArgumentException('An actor cache TTL must be at least one second.');
		}

		if ($maxEntries < 1) {
			throw new InvalidArgumentException('An actor cache needs at least one entry.');
		}
	}

	public function resolveByKeyId(string $keyId): RemoteActor
	{
		$now = $this->clock->now()->getTimestamp();
		$cached = $this->entries[$keyId] ?? null;

		if ($cached !== null) {
			if ($cached['expires_at'] > $now) {
				return $cached['actor'];
			}

			unset($this->entries[$keyId]);
		}

		$actor = $this->inner->resolveByKeyId($keyId);

		$this->prune($now);
		$this->entries[$keyId] = [
			'actor' => $actor,
			'expires_at' => $now + $this->ttlSeconds,
		];

		$this->enforceLimit();

		return $actor;
	}

	public function clear(): void
	{
		$this->entries = [];
	}

	public function size(): int
	{
		return count($this->entries);
	}

	private function prune(int $now): void
	{
		foreach ($this->entries as $keyId => $entry) {
			if ($entry['expires_at'] <= $now) {
				unset($this->entries[$keyId]);
			}
		}
	}

	private function enforceLimit(): void
	{
		while (count($this->entries) > $this->maxEntries) {
			$oldestKey = null;
			$oldestExpiry = PHP_INT_MAX;

			foreach ($this->entries as $keyId => $entry) {
				if ($entry['expires_at'] < $oldestExpiry) {
					$oldestExpiry = $entry['expires_at'];
					$oldestKey = $keyId;
				}
			}

			if ($oldestKey === null) {
				return;
			}

			unset($this->entries[$oldestKey]);
		}
	}
}
