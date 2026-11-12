<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Platform\Network\DnsHostResolver;
use AlohaSocial\Core\Platform\Network\HostResolver;

use function strtolower;
use function trim;

/**
 * A deterministic resolver for tests.
 *
 * Addresses supplied here are still checked by the production guard. The
 * test only chooses where a public test hostname points.
 */
final class TestHostResolver implements HostResolver
{
	/**
	 * @param array<string, list<string>> $addresses
	 */
	public function __construct(
		private readonly array $addresses,
		private readonly HostResolver $fallback = new DnsHostResolver(),
	) {
	}

	public function resolve(string $host): array
	{
		$host = strtolower(trim($host));

		return $this->addresses[$host] ?? $this->fallback->resolve($host);
	}
}
