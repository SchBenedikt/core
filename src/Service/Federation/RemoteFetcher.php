<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Platform\Network\HostResolver;

use function explode;
use function filter_var;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function ord;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;

/**
 * Fetches one remote document with the federation guard rails.
 *
 * The destination is checked before every request and every redirect: only
 * http/https is allowed, userinfo is refused, and every resolved address must
 * be globally routable. The validated addresses are passed to the HTTP client
 * for connection pinning. Redirects are followed manually with the same
 * checks. The body is capped while reading, not only after buffering it.
 *
 * This is the shared security core for remote media and thread/context
 * documents. Feature services map the generic reasons onto their documented
 * namespace instead of reimplementing the checks.
 */
final class RemoteFetcher
{
	private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

	/**
	 * @var list<string>
	 */
	private const BLOCKED_IPV4_CIDRS = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'255.255.255.255/32',
	];

	/**
	 * @var list<string>
	 */
	private const BLOCKED_IPV6_CIDRS = [
		'::/96',
		'::1/128',
		'64:ff9b::/96',
		'100::/64',
		'2001::/32',
		'2001:10::/28',
		'2001:20::/28',
		'2001:db8::/32',
		'2002::/16',
		'fc00::/7',
		'fe80::/10',
		'ff00::/8',
	];

	public function __construct(
		private readonly BoundedHttpClient $http,
		private readonly HostResolver $resolver,
	) {
	}

	/**
	 * @throws RemoteFetchException with a stable generic reason
	 */
	public function fetch(
		string $url,
		string $accept,
		int $timeoutSeconds,
		int $maxBytes,
		int $maxRedirects,
	): RemoteFetchResult {
		$timeoutSeconds = max(1, $timeoutSeconds);
		$maxBytes = max(1, $maxBytes);
		$maxRedirects = max(0, $maxRedirects);

		$current = trim($url);
		$redirects = 0;

		while (true) {
			$ips = $this->validatedAddresses($current);

			try {
				$response = $this->http->requestBounded(
					'GET',
					$current,
					['Accept' => $accept],
					'',
					$timeoutSeconds,
					$maxBytes,
					$ips,
				);
			} catch (ResponseLimitExceededException) {
				throw new RemoteFetchException(
					'too_large',
					'The remote response exceeded the allowed size.',
				);
			} catch (HttpClientException $exception) {
				$message = strtolower($exception->getMessage());
				$reason = str_contains($message, 'timed out') || str_contains($message, 'timeout')
					? 'timeout'
					: 'fetch_failed';

				throw new RemoteFetchException(
					$reason,
					'The remote document could not be fetched: ' . $exception->getMessage(),
				);
			}

			if (in_array($response->status, self::REDIRECT_STATUSES, true)) {
				if ($redirects >= $maxRedirects) {
					throw new RemoteFetchException(
						'too_many_redirects',
						'The remote document redirected too many times.',
					);
				}

				$location = $response->header('location');
				if ($location === null || trim($location) === '') {
					throw new RemoteFetchException(
						'redirect_invalid',
						'The remote document response has no usable Location header.',
					);
				}

				$current = $this->resolveRedirect($current, $location);
				++$redirects;

				continue;
			}

			if (!$response->isSuccessful()) {
				throw new RemoteFetchException(
					'http_error',
					'The remote document server answered with HTTP ' . $response->status . '.',
				);
			}

			if (strlen($response->body) > $maxBytes) {
				throw new RemoteFetchException(
					'too_large',
					'The remote response exceeded the allowed size.',
				);
			}

			return new RemoteFetchResult($response->body, $this->normalizeContentType($response));
		}
	}

	/**
	 * @return list<string> validated addresses, for connection pinning
	 *
	 * @throws RemoteFetchException when the URL or any resolved address is unsafe
	 */
	private function validatedAddresses(string $url): array
	{
		if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			throw new RemoteFetchException('invalid_url', 'The remote URL contains control characters.');
		}

		$parts = parse_url($url);
		if (!is_array($parts)) {
			throw new RemoteFetchException('invalid_url', 'The remote URL could not be parsed.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new RemoteFetchException('invalid_url', 'Only http and https URLs are allowed.');
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new RemoteFetchException('invalid_url', 'URLs with userinfo are not allowed.');
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '') {
			throw new RemoteFetchException('invalid_url', 'The remote URL has no host.');
		}

		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}

		if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
			throw new RemoteFetchException('ssrf_blocked', 'Local and private addresses are blocked.');
		}

		if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
			throw new RemoteFetchException('invalid_url', 'The remote URL has an invalid port.');
		}

		$addresses = $this->resolver->resolve($host);
		if ($addresses === []) {
			throw new RemoteFetchException('dns_failed', 'The remote host did not resolve.');
		}

		foreach ($addresses as $address) {
			if (!self::isPublicAddress($address)) {
				throw new RemoteFetchException('ssrf_blocked', 'Local and private addresses are blocked.');
			}
		}

		return $addresses;
	}

	public static function isPublicAddress(string $address): bool
	{
		$packed = @inet_pton($address);
		if ($packed === false) {
			return false;
		}

		// IPv4-mapped IPv6 addresses are checked as the IPv4 address they
		// actually connect to. The prefix is literal binary: inet_pton()
		// rejects the bare `::ffff:` form and would turn a false result into
		// an empty string, matching every address.
		$mappedPrefix = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
		if (strlen($packed) === 16 && str_starts_with($packed, $mappedPrefix)) {
			$ipv4 = inet_ntop(substr($packed, 12));

			return is_string($ipv4) && self::isPublicAddress($ipv4);
		}

		if (filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
		) === false) {
			return false;
		}

		$blocked = strlen($packed) === 4 ? self::BLOCKED_IPV4_CIDRS : self::BLOCKED_IPV6_CIDRS;
		foreach ($blocked as $cidr) {
			if (self::inCidr($packed, $cidr)) {
				return false;
			}
		}

		return true;
	}

	private static function inCidr(string $packedAddress, string $cidr): bool
	{
		[$network, $bits] = explode('/', $cidr, 2);
		$packedNetwork = inet_pton($network);
		if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
			return false;
		}

		$bits = (int) $bits;
		$fullBytes = intdiv($bits, 8);
		$remainingBits = $bits % 8;

		if (substr($packedAddress, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
			return false;
		}

		if ($remainingBits === 0) {
			return true;
		}

		$mask = (0xff << (8 - $remainingBits)) & 0xff;

		return (ord($packedAddress[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
	}

	private function resolveRedirect(string $base, string $location): string
	{
		$location = trim($location);
		if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
			throw new RemoteFetchException('redirect_invalid', 'The redirect target is not a valid URL.');
		}

		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
			return $location;
		}

		$baseParts = parse_url($base);
		if (!is_array($baseParts)) {
			throw new RemoteFetchException('redirect_invalid', 'The redirect base URL is invalid.');
		}

		$scheme = (string) ($baseParts['scheme'] ?? '');
		$host = (string) ($baseParts['host'] ?? '');
		if ($scheme === '' || $host === '') {
			throw new RemoteFetchException('redirect_invalid', 'The redirect base URL is incomplete.');
		}

		$origin = $scheme . '://' . $host;
		if (isset($baseParts['port'])) {
			$origin .= ':' . $baseParts['port'];
		}

		if (str_starts_with($location, '//')) {
			return $scheme . ':' . $location;
		}

		if (str_starts_with($location, '?')) {
			return $origin . (string) ($baseParts['path'] ?? '/') . $location;
		}

		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}

		$path = (string) ($baseParts['path'] ?? '/');
		$slash = strrpos($path, '/');
		$directory = $slash === false ? '/' : substr($path, 0, $slash + 1);

		return $origin . $directory . $location;
	}

	private function normalizeContentType(HttpResponse $response): ?string
	{
		$contentType = $response->header('content-type');
		if ($contentType === null) {
			return null;
		}

		$contentType = strtolower(trim(explode(';', $contentType, 2)[0]));

		return $contentType === '' ? null : $contentType;
	}
}
