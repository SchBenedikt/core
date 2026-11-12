<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Network;

/**
 * Resolves a hostname to its addresses before a request is allowed.
 *
 * The resolver is a port so the media guard can be tested and so a host can
 * use its own DNS policy. An empty result means the name did not resolve.
 */
interface HostResolver
{
	/**
	 * @return list<string> IPv4 and/or IPv6 addresses
	 */
	public function resolve(string $host): array;
}
