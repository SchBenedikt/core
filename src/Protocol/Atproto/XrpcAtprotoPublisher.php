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

/**
 * The authenticated XRPC write used for a Bluesky post.
 *
 * `com.atproto.repo.putRecord` is an upsert: a retry with the same rkey
 * updates the same record instead of creating a duplicate. The PDS is the
 * only remote service this class talks to, and the request transport (bearer
 * or DPoP, nonce retry) comes from `AtprotoPdsRequest`. Neither a token nor a
 * proof is ever logged or echoed in an exception.
 */
final class XrpcAtprotoPublisher implements AtprotoPublisher, AtprotoDeleter
{
	public const ENDPOINT = '/xrpc/com.atproto.repo.putRecord';

	public const DELETE_ENDPOINT = '/xrpc/com.atproto.repo.deleteRecord';

	public const TIMEOUT_SECONDS = AtprotoPdsRequest::TIMEOUT_SECONDS;

	private readonly AtprotoPdsRequest $requests;

	public function __construct(
		HttpClient $http,
		?DpopResourceProofProvider $dpop = null,
	) {
		$this->requests = new AtprotoPdsRequest($http, $dpop);
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
		try {
			return $this->requests->send('POST', $url, $account, $body, [
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			]);
		} catch (HttpClientException) {
			throw new AtprotoPublishFailedException(0, 'The AT Protocol PDS could not be reached.');
		}
	}
}
