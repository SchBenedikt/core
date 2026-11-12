<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Account resolver for tests, the demo and tools.
 */
final class InMemoryAtprotoAccountResolver implements AtprotoAccountResolver
{
	/**
	 * @var array<string, AtprotoAccount>
	 */
	private array $accounts = [];

	/**
	 * @param array<string, AtprotoAccount> $accounts keyed by local user id
	 */
	public function __construct(array $accounts = [])
	{
		$this->accounts = $accounts;
	}

	public function resolve(string $localUserId): ?AtprotoAccount
	{
		return $this->accounts[$localUserId] ?? null;
	}
}
