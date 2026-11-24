<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use JsonException;

use function is_array;
use function is_string;
use function strlen;
use function trim;

/**
 * One authenticated request to a PDS.
 *
 * Record writes and blob uploads share the same transport rules: the access
 * token goes into the Authorization header, a `DpopResourceProofProvider`
 * turns that into a DPoP resource request with the RFC 9449 `ath` binding, and
 * exactly one `use_dpop_nonce` challenge is retried with the server's nonce.
 * The caller maps the response and decides what a refusal means; a transport
 * failure is thrown as `HttpClientException` so each adapter can report it with
 * its own stable reason.
 */
final class AtprotoPdsRequest
{
	public const TIMEOUT_SECONDS = 20;

	public function __construct(
		private readonly HttpClient $http,
		private readonly ?DpopResourceProofProvider $dpop = null,
	) {
	}

	/**
	 * @param array<string, string> $headers
	 *
	 * @throws HttpClientException
	 */
	public function send(
		string $method,
		string $url,
		AtprotoAccount $account,
		string $body = '',
		array $headers = [],
	): HttpResponse {
		$dpop = $account->dpop ?? $this->dpop;
		$nonce = null;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$response = $this->http->request(
				$method,
				$url,
				$this->headers($method, $url, $account, $dpop, $headers, $nonce),
				$body,
				self::TIMEOUT_SECONDS,
			);

			if ($dpop === null || $attempt === 1) {
				return $response;
			}

			$nextNonce = $this->nonce($response);
			if ($nextNonce === null
				|| ($response->status !== 400 && $response->status !== 401)
				|| $this->errorCode($response) !== 'use_dpop_nonce'
			) {
				return $response;
			}

			$nonce = $nextNonce;
		}

		return $response;
	}

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array<string, string>
	 */
	private function headers(
		string $method,
		string $url,
		AtprotoAccount $account,
		?DpopResourceProofProvider $dpop,
		array $headers,
		?string $nonce,
	): array {
		if ($dpop === null) {
			return [
				'Authorization' => 'Bearer ' . $account->accessToken,
				...$headers,
			];
		}

		return [
			'Authorization' => 'DPoP ' . $account->accessToken,
			'DPoP' => $dpop->proofForResource($method, $url, $account->accessToken, $nonce),
			...$headers,
		];
	}

	private function nonce(HttpResponse $response): ?string
	{
		$nonce = $response->header('DPoP-Nonce');
		if (!is_string($nonce) || trim($nonce) === '') {
			return null;
		}

		return trim($nonce);
	}

	private function errorCode(HttpResponse $response): string
	{
		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return 'unexpected';
		}

		$error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

		return is_string($error) && $error !== '' && strlen($error) <= 64 ? $error : 'unexpected';
	}
}
