<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;
use AlohaSocial\Core\Platform\Network\HostResolver;

use function count;
use function filter_var;
use function inet_pton;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;

/**
 * Fetches one remote image with the federation guard rails.
 *
 * The destination is checked before every request and every redirect:
 * only http/https is allowed, userinfo is refused, and every resolved address
 * must be globally routable. The validated addresses are passed to the HTTP
 * client for connection pinning. Redirects are followed manually with the same
 * checks. The body is capped while reading, not after buffering it.
 */
final class RemoteMediaFetcher
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'media.remote_fetch';

	public const CONFIG_MAX_BYTES = 'media.remote_max_bytes';

	public const CONFIG_TIMEOUT_SECONDS = 'media.remote_timeout_seconds';

	public const CONFIG_MAX_REDIRECTS = 'media.remote_max_redirects';

	public const DEFAULT_TIMEOUT_SECONDS = 10;

	public const MAX_TIMEOUT_SECONDS = 30;

	public const DEFAULT_MAX_REDIRECTS = 3;

	public const MAX_REDIRECTS = 5;

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
		private readonly Config $config,
	) {
	}

	public function enabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, true);
	}

	/**
	 * @throws RemoteMediaException with a stable reason
	 */
	public function fetch(string $url): RemoteMediaResult
	{
		if (!$this->enabled()) {
			throw new RemoteMediaException(
				'remote_media_disabled',
				'Remote media fetching is disabled.',
			);
		}

		$maxBytes = $this->maxBytes();
		$timeout = $this->timeoutSeconds();
		$maxRedirects = $this->maxRedirects();

		$current = trim($url);
		$redirects = 0;

		while (true) {
			$ips = $this->validatedAddresses($current);

			try {
				$response = $this->http->requestBounded(
					'GET',
					$current,
					['Accept' => 'image/jpeg, image/png, image/webp'],
					'',
					$timeout,
					$maxBytes,
					$ips,
				);
			} catch (ResponseLimitExceededException) {
				throw new RemoteMediaException(
					'remote_media_too_large',
					'The remote media exceeded the configured size limit.',
				);
			} catch (HttpClientException $exception) {
				$message = strtolower($exception->getMessage());
				$reason = str_contains($message, 'timed out') || str_contains($message, 'timeout')
					? 'remote_media_timeout'
					: 'remote_media_fetch_failed';

				throw new RemoteMediaException(
					$reason,
					'The remote media could not be fetched: ' . $exception->getMessage(),
				);
			}

			if (in_array($response->status, self::REDIRECT_STATUSES, true)) {
				if ($redirects >= $maxRedirects) {
					throw new RemoteMediaException(
						'remote_media_too_many_redirects',
						'The remote media redirected too many times.',
					);
				}

				$location = $response->header('location');
				if ($location === null || trim($location) === '') {
					throw new RemoteMediaException(
						'remote_media_redirect_invalid',
						'The remote media response has no usable Location header.',
					);
				}

				$current = $this->resolveRedirect($current, $location);
				++$redirects;

				continue;
			}

			if (!$response->isSuccessful()) {
				throw new RemoteMediaException(
					'remote_media_http_error',
					'The remote media server answered with HTTP ' . $response->status . '.',
				);
			}

			if (strlen($response->body) > $maxBytes) {
				throw new RemoteMediaException(
					'remote_media_too_large',
					'The remote media exceeded the configured size limit.',
				);
			}

			return new RemoteMediaResult($response->body, $this->normalizeContentType($response));
		}
	}

	/**
	 * @return list<string> validated addresses, for connection pinning
	 *
	 * @throws RemoteMediaException when the URL or any resolved address is unsafe
	 */
	private function validatedAddresses(string $url): array
	{
		if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			throw new RemoteMediaException('remote_media_invalid_url', 'The remote media URL contains control characters.');
		}

		$parts = parse_url($url);
		if (!is_array($parts)) {
			throw new RemoteMediaException('remote_media_invalid_url', 'The remote media URL could not be parsed.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new RemoteMediaException('remote_media_invalid_url', 'Only http and https media URLs are allowed.');
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new RemoteMediaException('remote_media_invalid_url', 'Media URLs with userinfo are not allowed.');
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '') {
			throw new RemoteMediaException('remote_media_invalid_url', 'The remote media URL has no host.');
		}

		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}

		if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
			throw new RemoteMediaException('remote_media_ssrf_blocked', 'Local and private media addresses are blocked.');
		}

		if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
			throw new RemoteMediaException('remote_media_invalid_url', 'The remote media URL has an invalid port.');
		}

		$addresses = $this->resolver->resolve($host);
		if ($addresses === []) {
			throw new RemoteMediaException('remote_media_dns_failed', 'The remote media host did not resolve.');
		}

		foreach ($addresses as $address) {
			if (!$this->isPublicAddress($address)) {
				throw new RemoteMediaException(
					'remote_media_ssrf_blocked',
					'Local and private media addresses are blocked.',
				);
			}
		}

		return $addresses;
	}

	private function isPublicAddress(string $address): bool
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

			return is_string($ipv4) && $this->isPublicAddress($ipv4);
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
			if ($this->inCidr($packed, $cidr)) {
				return false;
			}
		}

		return true;
	}

	private function inCidr(string $packedAddress, string $cidr): bool
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
			throw new RemoteMediaException('remote_media_redirect_invalid', 'The redirect target is not a valid URL.');
		}

		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
			return $location;
		}

		$baseParts = parse_url($base);
		if (!is_array($baseParts)) {
			throw new RemoteMediaException('remote_media_redirect_invalid', 'The redirect base URL is invalid.');
		}

		$scheme = (string) ($baseParts['scheme'] ?? '');
		$host = (string) ($baseParts['host'] ?? '');
		if ($scheme === '' || $host === '') {
			throw new RemoteMediaException('remote_media_redirect_invalid', 'The redirect base URL is incomplete.');
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

	private function maxBytes(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_BYTES,
			Attachment::MAX_BYTES,
		);

		return max(1, min(Attachment::MAX_BYTES, $configured));
	}

	private function timeoutSeconds(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_TIMEOUT_SECONDS,
			self::DEFAULT_TIMEOUT_SECONDS,
		);

		return max(1, min(self::MAX_TIMEOUT_SECONDS, $configured));
	}

	private function maxRedirects(): int
	{
		$configured = $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_REDIRECTS,
			self::DEFAULT_MAX_REDIRECTS,
		);

		return max(0, min(self::MAX_REDIRECTS, $configured));
	}
}
