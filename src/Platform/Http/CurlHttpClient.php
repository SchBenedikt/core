<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_RESOLVE;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLOPT_WRITEFUNCTION;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;
use const FILTER_VALIDATE_IP;
use const PHP_INT_MAX;

/**
 * A curl-backed {@see BoundedHttpClient}.
 *
 * Redirects are not followed here: federation has to check every hop against
 * the instance's block list and pin the addresses it validated, so the caller
 * follows redirects deliberately. TLS verification is always on.
 *
 * The bounded path also stops reading at the byte limit. It does not rely on a
 * Content-Length header, which a peer can omit or lie about.
 */
final class CurlHttpClient implements BoundedHttpClient
{
	private const USER_AGENT = 'AlohaSocial-Core/0.1';

	public function request(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
	): HttpResponse {
		return $this->perform($method, $url, $headers, $body, $timeoutSeconds, PHP_INT_MAX, []);
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
		return $this->perform($method, $url, $headers, $body, $timeoutSeconds, $maxBytes, $resolvedIps);
	}

	/**
	 * @param array<string, string> $headers
	 * @param list<string>          $resolvedIps
	 */
	private function perform(
		string $method,
		string $url,
		array $headers,
		string $body,
		int $timeoutSeconds,
		int $maxBytes,
		array $resolvedIps,
	): HttpResponse {
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new HttpClientException('Refusing a non-HTTP URL: ' . $url);
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

		$timeout = max(1, $timeoutSeconds);
		$maxBytes = max(1, $maxBytes);
		$responseBody = '';
		$tooLarge = false;

		$options = [
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => $headerLines,
			CURLOPT_USERAGENT => self::USER_AGENT,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
				$trimmed = trim($line);
				if ($trimmed === '') {
					return strlen($line);
				}

				// A new status line starts a new response block (100-continue,
				// a proxy's answer): the headers of the last block win.
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
			CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge, $maxBytes): int {
				if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
					$tooLarge = true;

					// Returning a short count aborts the transfer without
					// buffering the rest of an untrusted response.
					return 0;
				}

				$responseBody .= $chunk;

				return strlen($chunk);
			},
		];

		$host = parse_url($url, PHP_URL_HOST);
		$port = parse_url($url, PHP_URL_PORT);
		$pinHost = is_string($host) ? $host : '';
		if (str_starts_with($pinHost, '[') && str_ends_with($pinHost, ']')) {
			$pinHost = substr($pinHost, 1, -1);
		}
		if ($pinHost !== '' && filter_var($pinHost, FILTER_VALIDATE_IP) === false && $resolvedIps !== []) {
			$port = is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);
			$address = str_contains($resolvedIps[0], ':') ? '[' . $resolvedIps[0] . ']' : $resolvedIps[0];
			$options[CURLOPT_RESOLVE] = [$pinHost . ':' . $port . ':' . $address];
		}

		curl_setopt_array($handle, $options);

		if ($body !== '') {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}

		$result = curl_exec($handle);
		if ($tooLarge) {
			unset($handle);

			throw new ResponseLimitExceededException('The response exceeded the allowed size.');
		}

		if ($result === false) {
			$reason = curl_error($handle);
			unset($handle);

			throw new HttpClientException('Request to ' . $url . ' failed: ' . $reason);
		}

		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		unset($handle);

		return new HttpResponse($status, $responseHeaders, $responseBody);
	}
}
