<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use InvalidArgumentException;
use JsonException;

use function is_array;
use function is_string;
use function json_decode;
use function rawurlencode;
use function rtrim;

/**
 * Reads a record with `com.atproto.repo.getRecord`.
 *
 * The endpoint takes the full AT URI. A missing record (400/404), a malformed
 * body or a transport failure all answer `null`, and the reply builder turns
 * that into a refusal instead of writing a reference without a CID.
 */
final class XrpcAtprotoRecordResolver implements AtprotoRecordResolver
{
	public const ENDPOINT = '/xrpc/com.atproto.repo.getRecord';

	public const TIMEOUT_SECONDS = 10;

	public function __construct(
		private readonly HttpClient $http,
	) {
	}

	public function getRecord(string $pdsUrl, string $uri): ?AtprotoRecord
	{
		$url = rtrim($pdsUrl, '/') . self::ENDPOINT . '?uri=' . rawurlencode($uri);

		try {
			$response = $this->http->request(
				'GET',
				$url,
				['Accept' => 'application/json'],
				'',
				self::TIMEOUT_SECONDS,
			);
		} catch (HttpClientException) {
			return null;
		}

		if (!$response->isSuccessful()) {
			return null;
		}

		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		$storedUri = $decoded['uri'] ?? null;
		$cid = $decoded['cid'] ?? null;
		$value = $decoded['value'] ?? null;
		if (!is_string($storedUri) || !is_string($cid) || !is_array($value)) {
			return null;
		}

		try {
			return new AtprotoRecord($storedUri, $cid, $value);
		} catch (InvalidArgumentException) {
			return null;
		}
	}
}
