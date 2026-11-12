<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use InvalidArgumentException;

/**
 * Session storage for tests, tools and the demo.
 *
 * Production hosts use their own secret store rather than this array; the
 * in-memory implementation exists to exercise the refresh service without a
 * credential table in the core.
 */
final class InMemoryAtprotoSessionStore implements AtprotoSessionStore
{
	/**
	 * @var array<string, AtprotoOAuthSession>
	 */
	private array $sessions = [];

	/**
	 * @param array<string, AtprotoOAuthSession> $sessions keyed by local user id
	 */
	public function __construct(array $sessions = [])
	{
		$this->sessions = $sessions;
	}

	public function find(string $localUserId): ?AtprotoOAuthSession
	{
		$this->assertUserId($localUserId);

		return $this->sessions[$localUserId] ?? null;
	}

	public function save(string $localUserId, AtprotoOAuthSession $session): void
	{
		$this->assertUserId($localUserId);
		$this->sessions[$localUserId] = $session;
	}

	private function assertUserId(string $localUserId): void
	{
		if ($localUserId === '' || trim($localUserId) !== $localUserId) {
			throw new InvalidArgumentException('An AT Protocol session needs a local user id.');
		}
	}
}
