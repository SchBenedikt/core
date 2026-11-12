<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use JsonException;

use function is_array;
use function is_string;

/**
 * The authenticated XRPC write used for a Bluesky post.
 *
 * `com.atproto.repo.putRecord` is an upsert: a retry with the same rkey
 * updates the same record instead of creating a duplicate. The PDS is the
 * only remote service this class talks to, and the access token is sent only
 * in the Authorization header.
 */
final class XrpcAtprotoPublisher implements AtprotoPublisher
{
	public const ENDPOINT = '/xrpc/com.atproto.repo.putRecord';

	public function __construct(
		private readonly HttpClient $http,
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

		try {
			$response = $this->http->request(
				'POST',
				$url,
				[
					'Authorization' => 'Bearer ' . $account->accessToken,
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				$body,
				20,
			);
		} catch (HttpClientException) {
			throw new AtprotoPublishFailedException(0, 'The AT Protocol PDS could not be reached.');
		}

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
}
