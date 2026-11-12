<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use JsonException;

use function is_array;
use function is_string;

/**
 * Resolves a signing key by fetching the actor document.
 *
 * The key id is turned into an actor URL (its fragment is dropped), the
 * document is fetched, and both the document id and the key id are checked
 * against what the request claimed. A document that does not confirm the key
 * id is refused: otherwise any actor could sign with a key it merely mentions.
 *
 * There is no cache in this class. Wrap it in {@see CachingActorResolver} to
 * avoid a round trip per signed request while keeping every identity check on
 * a miss; a host may also implement its own cache with the same rules.
 */
final class HttpActorResolver implements ActorResolver
{
	public const ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	public function __construct(
		private readonly HttpClient $http,
	) {
	}

	public function resolveByKeyId(string $keyId): RemoteActor
	{
		$actorId = $this->actorIdFromKeyId($keyId);

		try {
			$response = $this->http->request('GET', $actorId, ['Accept' => self::ACCEPT], '', 10);
		} catch (HttpClientException) {
			throw new UnknownActorException($keyId);
		}

		if (!$response->isSuccessful()) {
			throw new UnknownActorException($keyId);
		}

		try {
			$document = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new UnknownActorException($keyId);
		}

		if (!is_array($document)) {
			throw new UnknownActorException($keyId);
		}

		$id = $document['id'] ?? null;
		if (!is_string($id) || rtrim($id, '/') !== rtrim($actorId, '/')) {
			throw new UnknownActorException($keyId);
		}

		$publicKey = $document['publicKey'] ?? null;
		if (!is_array($publicKey)) {
			throw new UnknownActorException($keyId);
		}

		$documentKeyId = $publicKey['id'] ?? null;
		$publicKeyPem = $publicKey['publicKeyPem'] ?? null;
		$inbox = $document['inbox'] ?? null;
		$sharedInbox = $this->sharedInbox($document);

		if (!is_string($documentKeyId) || $documentKeyId !== $keyId) {
			throw new UnknownActorException($keyId);
		}

		if (!is_string($publicKeyPem) || trim($publicKeyPem) === '') {
			throw new UnknownActorException($keyId);
		}

		if (!is_string($inbox) || !self::isHttpUrl($inbox)) {
			throw new UnknownActorException($keyId);
		}

		return new RemoteActor($actorId, $keyId, $inbox, $publicKeyPem, $sharedInbox);
	}

	/**
	 * The optional server-wide inbox from `endpoints.sharedInbox`.
	 *
	 * A malformed optional value is ignored; the actor's individual inbox is
	 * still valid and delivery can fall back to it. A missing endpoints object
	 * is normal for peers that do not support shared inboxes.
	 *
	 * @param array<string, mixed> $document
	 */
	private function sharedInbox(array $document): ?string
	{
		$endpoints = $document['endpoints'] ?? null;
		if (!is_array($endpoints)) {
			return null;
		}

		$sharedInbox = $endpoints['sharedInbox'] ?? null;
		if (!is_string($sharedInbox) || !self::isHttpUrl($sharedInbox)) {
			return null;
		}

		return mb_strlen($sharedInbox) <= RemoteActor::MAX_URL_LENGTH ? $sharedInbox : null;
	}

	private function actorIdFromKeyId(string $keyId): string
	{
		$fragment = strpos($keyId, '#');
		$actorId = $fragment === false ? $keyId : substr($keyId, 0, $fragment);

		if (!self::isHttpUrl($actorId)) {
			throw new UnknownActorException($keyId);
		}

		return $actorId;
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
