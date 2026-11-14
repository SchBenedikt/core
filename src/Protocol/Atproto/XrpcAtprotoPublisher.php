<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use JsonException;

use function is_array;
use function is_string;
use function strlen;
use function trim;

/**
 * The authenticated XRPC write used for a Bluesky post.
 *
 * `com.atproto.repo.putRecord` is an upsert: a retry with the same rkey
 * updates the same record instead of creating a duplicate. The PDS is the
 * only remote service this class talks to, and the access token is sent only
 * in the Authorization header.
 *
 * With a `DpopResourceProofProvider` every request is a DPoP resource request
 * (`Authorization: DPoP <token>` plus a proof carrying `ath`), which is what an
 * AT Protocol OAuth token needs; without one the adapter keeps sending the
 * host's bearer token so an app-password-style host is unchanged. Exactly one
 * `use_dpop_nonce` challenge is retried with the server's nonce. Neither a
 * token nor a proof is ever logged or echoed in an exception.
 */
final class XrpcAtprotoPublisher implements AtprotoPublisher, AtprotoDeleter
{
	public const ENDPOINT = '/xrpc/com.atproto.repo.putRecord';

	public const DELETE_ENDPOINT = '/xrpc/com.atproto.repo.deleteRecord';

	public const TIMEOUT_SECONDS = 20;

	public function __construct(
		private readonly HttpClient $http,
		private readonly ?DpopResourceProofProvider $dpop = null,
	) {
	}

	public function publish(AtprotoAccount $account, string $rkey, array $record): string
	{
		$url = rtrim($account->pdsUrl, '/') . self::ENDPOINT;

		try {
			$body = json_encode([
				'repo' => $account->did,
				'collection' => AtprotoPostRecord::COLLECTION,
				'rkey' => $rkey,
				'record' => $record,
				'validate' => true,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new AtprotoPublishFailedException(0, 'The AT Protocol record could not be encoded.');
		}

		$response = $this->send($url, $account, $body);

		if (!$response->isSuccessful()) {
			throw new AtprotoPublishFailedException($response->status, 'The AT Protocol PDS refused the record.');
		}

		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new AtprotoPublishFailedException(502, 'The AT Protocol PDS returned an invalid response.');
		}

		$uri = is_array($decoded) ? ($decoded['uri'] ?? null) : null;
		if (!is_string($uri) || !str_starts_with($uri, 'at://') || strlen($uri) > 512) {
			throw new AtprotoPublishFailedException(502, 'The AT Protocol PDS returned no record URI.');
		}

		return $uri;
	}

	public function delete(AtprotoAccount $account, string $rkey): void
	{
		$url = rtrim($account->pdsUrl, '/') . self::DELETE_ENDPOINT;

		try {
			$body = json_encode([
				'repo' => $account->did,
				'collection' => AtprotoPostRecord::COLLECTION,
				'rkey' => $rkey,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new AtprotoPublishFailedException(0, 'The AT Protocol record key could not be encoded.');
		}

		$response = $this->send($url, $account, $body);

		// A missing record already has the desired end state. Treat it as a
		// successful idempotent delete so a retry after a lost response or a
		// host-side cleanup can finish.
		if (!$response->isSuccessful() && $response->status !== 404) {
			throw new AtprotoPublishFailedException($response->status, 'The AT Protocol PDS refused the delete.');
		}
	}

	private function send(string $url, AtprotoAccount $account, string $body): HttpResponse
	{
		$nonce = null;
		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$response = $this->http->request(
					'POST',
					$url,
					$this->headers($url, $account, $nonce),
					$body,
					self::TIMEOUT_SECONDS,
				);
			} catch (HttpClientException) {
				throw new AtprotoPublishFailedException(0, 'The AT Protocol PDS could not be reached.');
			}

			if ($this->dpop === null || $attempt === 1) {
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

		throw new AtprotoPublishFailedException(401, 'The AT Protocol PDS kept refusing the DPoP proof.');
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(string $url, AtprotoAccount $account, ?string $nonce): array
	{
		$headers = [
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		];

		if ($this->dpop === null) {
			$headers['Authorization'] = 'Bearer ' . $account->accessToken;

			return $headers;
		}

		return [
			'Authorization' => 'DPoP ' . $account->accessToken,
			'DPoP' => $this->dpop->proofForResource('POST', $url, $account->accessToken, $nonce),
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
