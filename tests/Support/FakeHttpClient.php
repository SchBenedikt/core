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

/**
 * An HTTP client that records what was sent and answers from a queue.
 *
 * The default answer is 202, so a delivery test only states the interesting
 * case (a peer that refuses).
 */
final class FakeHttpClient implements BoundedHttpClient
{
	/**
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: string, timeout: int}>
	 */
	public array $requests = [];

	/**
	 * @var list<HttpResponse|HttpClientException>
	 */
	private array $answers = [];

	public function queueResponse(HttpResponse $response): void
	{
		$this->answers[] = $response;
	}

	public function queueTransportFailure(HttpClientException $exception): void
	{
		$this->answers[] = $exception;
	}

	public function request(
		string $method,
		string $url,
		array $headers = [],
		string $body = '',
		int $timeoutSeconds = 10,
	): HttpResponse {
		return $this->answer($method, $url, $headers, $body, $timeoutSeconds, PHP_INT_MAX);
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
		return $this->answer($method, $url, $headers, $body, $timeoutSeconds, $maxBytes);
	}

	private function answer(
		string $method,
		string $url,
		array $headers,
		string $body,
		int $timeoutSeconds,
		int $maxBytes,
	): HttpResponse {
		$this->requests[] = [
			'method' => $method,
			'url' => $url,
			'headers' => $headers,
			'body' => $body,
			'timeout' => $timeoutSeconds,
		];

		$answer = array_shift($this->answers);
		if ($answer instanceof HttpClientException) {
			throw $answer;
		}

		$answer ??= new HttpResponse(202);
		if (strlen($answer->body) > $maxBytes) {
			throw new ResponseLimitExceededException('The response exceeded the allowed size.');
		}

		return $answer;
	}
}
