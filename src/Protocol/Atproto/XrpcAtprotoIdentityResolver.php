<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use JsonException;

use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function strlen;
use function trim;

/**
 * Resolves a handle through the publishing account's PDS.
 *
 * `com.atproto.identity.resolveHandle` is a public, unauthenticated GET on the
 * PDS; a handle the server does not know answers 400/404. Any failure is
 * reported as "unresolved" so the caller refuses the record instead of writing
 * a mention facet with a guessed or missing DID.
 */
final class XrpcAtprotoIdentityResolver implements AtprotoIdentityResolver
{
	public const ENDPOINT = '/xrpc/com.atproto.identity.resolveHandle';

	public const TIMEOUT_SECONDS = 10;

	public const MAX_DID_LENGTH = 512;

	public function __construct(
		private readonly HttpClient $http,
	) {
	}

	public function resolveHandle(string $pdsUrl, string $handle): ?string
	{
		$handle = trim($handle);
		if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $handle) !== 1) {
			return null;
		}

		$url = rtrim($pdsUrl, '/') . self::ENDPOINT . '?handle=' . rawurlencode($handle);

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
			$decoded = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		$did = is_array($decoded) ? ($decoded['did'] ?? null) : null;
		if (!is_string($did)
			|| $did === ''
			|| strlen($did) > self::MAX_DID_LENGTH
			|| preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $did) !== 1
		) {
			return null;
		}

		return $did;
	}
}
