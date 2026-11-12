<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Network;

use function dns_get_record;
use function filter_var;
use function function_exists;
use function gethostbynamel;
use function inet_pton;

use const DNS_A;
use const DNS_AAAA;
use const FILTER_VALIDATE_IP;

/**
 * Resolves a hostname with the system resolver.
 *
 * Both address families are requested, because accepting only the first
 * family would let a name with one public and one private answer bypass the
 * guard. A literal IP is returned unchanged.
 */
final class DnsHostResolver implements HostResolver
{
	public function resolve(string $host): array
	{
		$host = trim($host);
		if ($host === '') {
			return [];
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$addresses = [];

		if (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_A | DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $record) {
					$address = $record['ip'] ?? $record['ipv6'] ?? null;
					if (is_string($address) && inet_pton($address) !== false) {
						$addresses[$address] = true;
					}
				}
			}
		}

		if ($addresses === []) {
			$ipv4 = @gethostbynamel($host);
			if (is_array($ipv4)) {
				foreach ($ipv4 as $address) {
					if (is_string($address) && inet_pton($address) !== false) {
						$addresses[$address] = true;
					}
				}
			}
		}

		return array_keys($addresses);
	}
}
