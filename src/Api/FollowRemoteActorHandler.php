<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\UnknownActorException;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Service\Federation\DeliveryFailedException;
use AlohaSocial\Core\Service\FollowingNotConfiguredException;
use AlohaSocial\Core\Service\FollowingBlockedException;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\ValidationException;

use function filter_var;
use function in_array;
use function is_string;
use function parse_url;
use function strtolower;
use function trim;

/**
 * `POST /api/v1/following`
 *
 * Follows a remote actor by storing a pending edge and queueing a signed
 * ActivityPub `Follow`. A new edge answers 201 with a Location and
 * `state: pending`; repeating the call answers 200 and queues nothing, so a
 * retried request does not produce duplicate Follows. The worker retries a
 * failed delivery. The edge becomes accepted or rejected only through a
 * verified inbound Accept/Reject.
 */
final class FollowRemoteActorHandler implements Handler
{
	public function __construct(
		private readonly FollowingService $following,
		private readonly ?RemoteActorFetcher $remoteActors = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$body = $request->json();

		$actor = $body['actor'] ?? null;
		if (!is_string($actor)) {
			throw HttpException::validationFailed(['actor' => 'actor must be a string.']);
		}

		$inbox = $body['inbox'] ?? null;
		if (!is_string($inbox)) {
			$inbox = null;
		}

		// Malformed values are refused before any network fetch; the same
		// rule {@see FollowingService} applies again on the verified values.
		$errors = [];
		if (!$this->isAbsoluteHttpUrl($actor)) {
			$errors['actor'] = 'actor must be an absolute http(s) URL.';
		}
		if ($inbox !== null && !$this->isAbsoluteHttpUrl($inbox)) {
			$errors['inbox'] = 'inbox must be an absolute http(s) URL.';
		}
		if ($errors !== []) {
			throw HttpException::validationFailed($errors);
		}

		if ($this->remoteActors !== null) {
			// The client names an actor and maybe an inbox, but only the
			// actor document fetched through the bounded resolver decides
			// where the Follow goes. A supplied inbox that matches neither
			// advertised endpoint is a client bug or an attempt to redirect
			// deliveries, so it is refused before anything is stored.
			try {
				$resolved = $this->resolveActor($actor);
			} catch (UnknownActorException) {
				throw HttpException::validationFailed([
					'actor' => 'The remote actor could not be resolved.',
				]);
			}

			$advertised = [$resolved->inboxUrl];
			if ($resolved->sharedInboxUrl !== null) {
				$advertised[] = $resolved->sharedInboxUrl;
			}

			if ($inbox !== null && !in_array($inbox, $advertised, true)) {
				throw HttpException::validationFailed([
					'inbox' => 'inbox does not match an inbox advertised by the actor.',
				]);
			}

			$actor = $resolved->actorId;
			$inbox = $resolved->sharedInboxUrl ?? $resolved->inboxUrl;
		} elseif ($inbox === null) {
			throw HttpException::validationFailed(['inbox' => 'inbox must be a string.']);
		}

		try {
			$result = $this->following->follow($userId, $actor, $inbox);
		} catch (FollowingBlockedException) {
			throw new HttpException(403, 'blocked_actor', 'This remote actor has blocked your account.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (FollowingNotConfiguredException $exception) {
			throw new HttpException(503, 'federation_not_configured', $exception->getMessage());
		} catch (DeliveryFailedException|HttpClientException) {
			throw new HttpException(
				502,
				'delivery_failed',
				'The remote inbox did not accept the Follow.',
			);
		} catch (ConfigurationException) {
			throw new HttpException(
				503,
				'instance_not_configured',
				'The instance URL is not configured.',
			);
		}

		$followed = $result->followed;

		return Response::json(
			['following' => $followed->toArray()],
			$result->created ? 201 : 200,
			$result->created
				? ['Location' => ApiVersion::BASE_PATH . '/following/' . rawurlencode($followed->id)]
				: [],
		);
	}

	/**
	 * Resolves the actor document, following one `movedTo` hop. A relocation
	 * is only honoured when the destination document verifies; the follow
	 * then targets the canonical actor the peer actually operates.
	 *
	 * @throws UnknownActorException
	 */
	private function resolveActor(string $actor): RemoteActor
	{
		$fetcher = $this->remoteActors;
		if ($fetcher === null) {
			throw new UnknownActorException($actor);
		}

		$resolved = $fetcher->fetch($actor);
		$movedTo = $resolved->movedTo;

		return $movedTo === null ? $resolved : $fetcher->fetch($movedTo);
	}

	private function isAbsoluteHttpUrl(string $value): bool
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
