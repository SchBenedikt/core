<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Platform\Network\HostResolver;

use function filter_var;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const FILTER_VALIDATE_IP;

/**
 * Validates a destination before an outgoing federation request.
 *
 * Delivery, actor-document resolution and inbox discovery all follow URLs
 * supplied by a peer. This guard applies the same http(s), userinfo,
 * literal-private-address and DNS rules as the remote-media/thread fetcher.
 * When a host resolver is present, every resolved address has to be publicly
 * routable; a caller that has a bounded HTTP client can then pin the request
 * to those checked addresses.
 *
 * A missing resolver still blocks localhost and literal private addresses so
 * the test/demo assembly cannot reach the local network by accident.
 */
final class FederationTargetGuard
{
	public function __construct(
		private readonly ?HostResolver $resolver = null,
		private readonly ?ModerationPolicy $moderationPolicy = null,
	) {
	}

	/**
	 * @return list<string> validated addresses for connection pinning; empty
	 *                      when no resolver was configured
	 *
	 * @throws RemoteFetchException with a stable reason
	 */
	public function addressesFor(string $url): array
	{
		if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			throw new RemoteFetchException('invalid_url', 'The federation URL contains control characters.');
		}

		$parts = parse_url($url);
		if (!is_array($parts)) {
			throw new RemoteFetchException('invalid_url', 'The federation URL could not be parsed.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new RemoteFetchException('invalid_url', 'Only http and https URLs are allowed.');
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new RemoteFetchException('invalid_url', 'URLs with userinfo are not allowed.');
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '') {
			throw new RemoteFetchException('invalid_url', 'The federation URL has no host.');
		}

		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}

		$host = rtrim($host, '.');

		if ($this->moderationPolicy?->allowsDomain($host) === false) {
			throw new RemoteFetchException('moderation_blocked', 'The destination domain is blocked by this instance.');
		}

		if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
			throw new RemoteFetchException('ssrf_blocked', 'Local and private addresses are blocked.');
		}

		if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
			throw new RemoteFetchException('invalid_url', 'The federation URL has an invalid port.');
		}

		// Check literals even without a resolver. A hostname still needs the
		// resolver because only DNS can tell whether it points into a private
		// range.
		if (filter_var($host, FILTER_VALIDATE_IP) !== false
			&& !RemoteFetcher::isPublicAddress($host)
		) {
			throw new RemoteFetchException('ssrf_blocked', 'Local and private addresses are blocked.');
		}

		if ($this->resolver === null) {
			return [];
		}

		$addresses = $this->resolver->resolve($host);
		if ($addresses === []) {
			throw new RemoteFetchException('dns_failed', 'The federation host did not resolve.');
		}

		foreach ($addresses as $address) {
			if (!is_string($address) || !RemoteFetcher::isPublicAddress($address)) {
				throw new RemoteFetchException('ssrf_blocked', 'Local and private addresses are blocked.');
			}
		}

		return $addresses;
	}
}
