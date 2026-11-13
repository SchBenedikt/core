<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Federation\ActorInboxResolver;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use JsonException;

use function is_array;
use function is_string;

/**
 * Looks up the individual inbox from a remote actor document.
 *
 * A missing, malformed or mismatching document is a normal "cannot deliver"
 * result, not an exception: the local block is still valid and must not fail
 * because a peer is offline. The actor id in the document has to match the
 * requested URL, otherwise a redirect or stale document could direct the
 * block to an unrelated account.
 */
final class HttpActorInboxResolver implements ActorInboxResolver
{
	public function __construct(
		private readonly HttpClient $http,
	) {
	}

	public function inboxFor(string $actorId): ?string
	{
		$actorId = trim($actorId);
		if (!self::isHttpUrl($actorId)) {
			return null;
		}

		try {
			$response = $this->http->request(
				'GET',
				$actorId,
				['Accept' => HttpActorResolver::ACCEPT],
				'',
				10,
			);
		} catch (HttpClientException) {
			return null;
		}

		if (!$response->isSuccessful()) {
			return null;
		}

		try {
			$document = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		if (!is_array($document)) {
			return null;
		}

		$id = $document['id'] ?? null;
		if (!is_string($id) || rtrim($id, '/') !== rtrim($actorId, '/')) {
			return null;
		}

		$inbox = $document['inbox'] ?? null;

		return is_string($inbox) && self::isHttpUrl($inbox) && mb_strlen($inbox) <= RemoteActor::MAX_URL_LENGTH
			? $inbox
			: null;
	}

	private static function isHttpUrl(string $value): bool
	{
		if ($value === '' || trim($value) !== $value) {
			return false;
		}

		if (filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);
		$host = parse_url($value, PHP_URL_HOST);

		return is_string($scheme)
			&& in_array(strtolower($scheme), ['http', 'https'], true)
			&& is_string($host)
			&& $host !== '';
	}
}
