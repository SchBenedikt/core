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
use JsonException;

use function is_array;
use function json_decode;
use function rtrim;

/**
 * Uploads a blob with `com.atproto.repo.uploadBlob`.
 *
 * The body is the raw media, the Content-Type is the media's MIME type, and
 * the request uses the same bearer/DPoP transport as the record writer. A
 * refused upload, a transport failure and a success without a usable blob
 * reference are all reported as `AtprotoBlobException`; no partial embed is
 * ever returned.
 */
final class XrpcAtprotoBlobUploader implements AtprotoBlobUploader
{
	public const ENDPOINT = '/xrpc/com.atproto.repo.uploadBlob';

	private readonly AtprotoPdsRequest $requests;

	public function __construct(
		HttpClient $http,
		?DpopResourceProofProvider $dpop = null,
	) {
		$this->requests = new AtprotoPdsRequest($http, $dpop);
	}

	public function upload(AtprotoAccount $account, AtprotoMedia $media): AtprotoBlob
	{
		$url = rtrim($account->pdsUrl, '/') . self::ENDPOINT;

		try {
			$response = $this->requests->send('POST', $url, $account, $media->bytes, [
				'Content-Type' => $media->mimeType,
				'Accept' => 'application/json',
			]);
		} catch (HttpClientException) {
			throw new AtprotoBlobException(
				AtprotoBlobException::UPLOAD_FAILED,
				'The AT Protocol PDS could not be reached for the blob upload.',
			);
		}

		if (!$response->isSuccessful()) {
			throw new AtprotoBlobException(
				AtprotoBlobException::UPLOAD_FAILED,
				'The AT Protocol PDS refused the blob upload.',
			);
		}

		try {
			$decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$decoded = null;
		}

		$blob = is_array($decoded)
			? AtprotoBlob::fromXrpcResponse($decoded, $media->mimeType, $media->size())
			: null;

		if ($blob === null) {
			throw new AtprotoBlobException(
				AtprotoBlobException::MISSING,
				'The AT Protocol PDS returned no blob reference.',
			);
		}
		if ($blob->mimeType !== $media->mimeType || $blob->size !== $media->size()) {
			throw new AtprotoBlobException(
				AtprotoBlobException::UPLOAD_FAILED,
				'The AT Protocol PDS returned blob metadata that does not match the uploaded media.',
			);
		}

		return $blob;
	}
}
