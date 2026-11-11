<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Http;

use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;

/**
 * A curl-backed {@see HttpClient}.
 *
 * Redirects are not followed here: federation has to check every hop against
 * the instance's block list and pin the addresses it validated, so the caller
 * follows redirects deliberately. TLS verification is always on.
 */
final class CurlHttpClient implements HttpClient
{
	private const USER_AGENT = 'AlohaSocial-Core/0.1';

	public function request(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
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

		curl_setopt_array($handle, [
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
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
		]);

		if ($body !== '') {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}

		$responseBody = curl_exec($handle);
		if ($responseBody === false) {
			$reason = curl_error($handle);
			unset($handle);

			throw new HttpClientException('Request to ' . $url . ' failed: ' . $reason);
		}

		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		unset($handle);

		return new HttpResponse($status, $responseHeaders, (string) $responseBody);
	}
}
