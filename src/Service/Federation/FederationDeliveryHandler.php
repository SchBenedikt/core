<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use JsonException;

use function is_array;
use function is_string;

/**
 * Delivers one ActivityPub activity to one inbox.
 *
 * The activity travels exactly as it was built when the post was written —
 * re-building it here would let a later edit change what a retry sends. The
 * request is signed with the actor's key over the exact host and target that
 * are on the wire, and a non-2xx answer fails the job so the queue retries
 * it with a backoff.
 */
final class FederationDeliveryHandler implements JobHandler
{
	public function __construct(
		private readonly HttpClient $http,
		private readonly RsaKeyPair $signingKey,
		private readonly Clock $clock,
		private readonly ?FederationTargetGuard $targetGuard = null,
	) {
	}

	/**
	 * @throws DeliveryFailedException on a non-2xx answer
	 * @throws InvalidJobPayloadException when the payload is malformed
	 * @throws JsonException when the activity cannot be encoded
	 */
	public function handle(Job $job): void
	{
		$inbox = $job->payload['inbox'] ?? null;
		$activity = $job->payload['activity'] ?? null;
		$keyId = $job->payload['key_id'] ?? null;

		if (!is_string($inbox) || !is_array($activity) || !is_string($keyId)) {
			throw new InvalidJobPayloadException(
				'A delivery job needs "inbox", "activity" and "key_id".',
			);
		}

		$this->deliver($inbox, $activity, $keyId);
	}

	/**
	 * Synchronous variant for use cases whose local state depends on the
	 * answer, such as a Follow that is rolled back when the peer refuses it.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @throws DeliveryFailedException on a non-2xx answer
	 * @throws InvalidJobPayloadException when the inbox is not an absolute http(s) URL
	 * @throws RemoteFetchException when the target is not a public http(s) destination
	 * @throws JsonException when the activity cannot be encoded
	 */
	public function deliver(string $inbox, array $activity, string $keyId): void
	{
		$parts = parse_url($inbox);
		$host = $parts['host'] ?? null;
		$scheme = $parts['scheme'] ?? null;
		if (!is_string($host) || ($scheme !== 'https' && $scheme !== 'http')) {
			throw new InvalidJobPayloadException('Not an absolute http(s) inbox URL: ' . $inbox);
		}

		// Validate before signing or opening a connection. With a resolver this
		// also supplies the addresses a bounded client can pin the request to.
		$addresses = $this->targetGuard?->addressesFor($inbox) ?? [];

		$hostHeader = $host;
		if (isset($parts['port'])) {
			$hostHeader .= ':' . $parts['port'];
		}

		$target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

		$body = json_encode(
			$activity,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);

		$headers = HttpSignature::sign(
			$this->signingKey,
			$keyId,
			'POST',
			$target,
			$hostHeader,
			$body,
			$this->clock->now(),
		);
		$headers['Content-Type'] = 'application/activity+json';

		$response = $this->http instanceof BoundedHttpClient && $addresses !== []
			? $this->http->requestBounded('POST', $inbox, $headers, $body, 20, 65_536, $addresses)
			: $this->http->request('POST', $inbox, $headers, $body, 20);

		if (!$response->isSuccessful()) {
			throw new DeliveryFailedException($inbox, $response->status);
		}
	}
}
