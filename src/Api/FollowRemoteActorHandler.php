<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Service\Federation\DeliveryFailedException;
use AlohaSocial\Core\Service\FollowingNotConfiguredException;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\ValidationException;

use function is_string;

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
			throw HttpException::validationFailed(['inbox' => 'inbox must be a string.']);
		}

		try {
			$result = $this->following->follow($userId, $actor, $inbox);
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
}
