<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use JsonException;

use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function rtrim;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Resolves a signing key by fetching the actor document.
 *
 * The key id is turned into an actor URL (its fragment is dropped), the
 * document is fetched, and both the document id and the key id are checked
 * against what the request claimed. A document that does not confirm the key
 * id is refused: otherwise any actor could sign with a key it merely mentions.
 *
 * The fetch is an inbound, peer-controlled request, so it always goes through
 * the bounded client and connects only to addresses the target guard approved.
 * There is no unbounded fallback: without a guard, or without a validated
 * address to pin, the actor stays unknown rather than being fetched unchecked.
 *
 * There is no cache in this class. Wrap it in {@see CachingActorResolver} to
 * avoid a round trip per signed request while keeping every identity check on
 * a miss; a host may also implement its own cache with the same rules.
 */
final class HttpActorResolver implements PinnedActorResolver
{
	public const ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	/** The connection and total transfer budget of one actor fetch. */
	public const TIMEOUT_SECONDS = 10;

	/** The largest actor document accepted, independent of any header. */
	public const MAX_DOCUMENT_BYTES = 1_048_576;

	public function __construct(
		private readonly HttpClient $http,
		private readonly ?FederationTargetGuard $targetGuard = null,
	) {
	}

	public function resolveByKeyId(string $keyId): RemoteActor
	{
		$actorId = $this->actorIdFromKeyId($keyId);

		if ($this->targetGuard === null) {
			// Nothing checked this target, so there is no address to pin the
			// connection to. Fail closed instead of falling back to a fetch
			// that could reach the local network.
			throw new UnknownActorException($keyId);
		}

		try {
			$addresses = $this->targetGuard->addressesFor($actorId);
		} catch (RemoteFetchException) {
			throw new UnknownActorException($keyId);
		}

		if ($addresses === []) {
			// The guard only inspected a literal host; a hostname without a
			// resolver may still point into a private range.
			throw new UnknownActorException($keyId);
		}

		return $this->resolveByKeyIdAtAddresses($keyId, $addresses);
	}

	public function resolveByKeyIdAtAddresses(string $keyId, array $addresses): RemoteActor
	{
		$actorId = $this->actorIdFromKeyId($keyId);
		$response = $this->fetch($keyId, $actorId, $addresses);

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
	 * @param list<string> $addresses validated addresses, or empty for a
	 *                                caller that deliberately skipped the check
	 */
	private function fetch(string $keyId, string $actorId, array $addresses): HttpResponse
	{
		if (!$this->http instanceof BoundedHttpClient) {
			// An unbounded client can buffer a peer-controlled document until
			// an external limit stops it. That is not acceptable on this path.
			throw new UnknownActorException($keyId);
		}

		try {
			$response = $this->http->requestBounded(
				'GET',
				$actorId,
				['Accept' => self::ACCEPT],
				'',
				self::TIMEOUT_SECONDS,
				self::MAX_DOCUMENT_BYTES,
				$addresses,
			);
		} catch (HttpClientException) {
			// The bounded client also reports an oversized document here.
			throw new UnknownActorException($keyId);
		}

		if (!$response->isSuccessful()) {
			throw new UnknownActorException($keyId);
		}

		return $response;
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
