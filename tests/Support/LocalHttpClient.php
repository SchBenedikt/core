<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Platform\Http\ResponseLimitExceededException;

use function count;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function explode;
use function is_array;
use function max;
use function min;
use function parse_url;
use function preg_match;
use function strlen;
use function strtolower;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLOPT_WRITEFUNCTION;
use const FILTER_VALIDATE_IP;
use const PHP_INT_MAX;

/**
 * Test-only transport: it sends the request to the local fixture server.
 *
 * Production still uses CurlHttpClient with the validated address pinned.
 * This client exists so a test can exercise a real HTTP server without
 * allowing loopback addresses through the production SSRF guard.
 */
final class LocalHttpClient implements BoundedHttpClient
{
	/**
	 * @var list<string>
	 */
	public array $requests = [];

	public function __construct(
		private readonly string $baseUrl,
	) {
	}

	public function request(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
	): HttpResponse {
		return $this->send($method, $url, $headers, $body, $timeoutSeconds, PHP_INT_MAX);
	}

	public function requestBounded(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
		int $maxBytes = 1_048_576,
		array $resolvedIps = [],
	): HttpResponse {
		return $this->send($method, $url, $headers, $body, $timeoutSeconds, $maxBytes);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function send(
		string $method,
		string $url,
		array $headers,
		string $body,
		int $timeoutSeconds,
		int $maxBytes,
	): HttpResponse {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			throw new HttpClientException('The test URL is invalid.');
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new HttpClientException('Refusing a non-HTTP URL.');
		}

		$this->requests[] = $url;

		$path = (string) ($parts['path'] ?? '/');
		if (isset($parts['query'])) {
			$path .= '?' . $parts['query'];
		}

		$handle = curl_init();
		if ($handle === false) {
			throw new HttpClientException('curl is not available.');
		}

		$responseHeaders = [];
		$headerLines = [];
		foreach ($headers as $name => $value) {
			$headerLines[] = $name . ': ' . $value;
		}

		$responseBody = '';
		$tooLarge = false;
		$timeout = max(1, $timeoutSeconds);
		$limit = max(1, $maxBytes);

		$options = [
			CURLOPT_URL => $this->baseUrl . $path,
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => $headerLines,
			CURLOPT_USERAGENT => 'AlohaSocial-Core-Tests/0.1',
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
				$trimmed = trim($line);
				if ($trimmed === '') {
					return strlen($line);
				}

				if (preg_match('#^HTTP/#i', $trimmed) === 1) {
					$responseHeaders = [];

					return strlen($line);
				}

				$parts = explode(':', $trimmed, 2);
				if (count($parts) === 2) {
					$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
				}

				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge, $limit): int {
				if (strlen($responseBody) + strlen($chunk) > $limit) {
					$tooLarge = true;

					return 0;
				}

				$responseBody .= $chunk;

				return strlen($chunk);
			},
		];

		curl_setopt_array($handle, $options);
		if ($body !== '') {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}

		$result = curl_exec($handle);
		if ($tooLarge) {
			curl_close($handle);

			throw new ResponseLimitExceededException('The response exceeded the allowed size.');
		}

		if ($result === false) {
			$reason = curl_error($handle);
			curl_close($handle);

			throw new HttpClientException('Request failed: ' . $reason);
		}

		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		curl_close($handle);

		return new HttpResponse($status, $responseHeaders, $responseBody);
	}
}
