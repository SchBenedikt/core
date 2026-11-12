<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Service\Federation\DeliveryFailedException;
use AlohaSocial\Core\Service\FollowingNotConfiguredException;
use AlohaSocial\Core\Service\FollowingService;

/**
 * `DELETE /api/v1/following/{id}`
 *
 * Sends the matching `Undo` to the stored inbox and removes the edge only
 * after the peer accepted it. A failed delivery answers 502 and leaves the
 * follow in place, so the same request can be retried safely.
 *
 * An id of another account is answered with 404 rather than 403: the id alone
 * must not reveal that another account follows this actor.
 */
final class UnfollowRemoteActorHandler implements Handler
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

		$id = (string) $request->attribute('id', '');
		if ($id === '') {
			throw HttpException::notFound('Followed actor not found.');
		}

		try {
			$removed = $this->following->unfollowById($userId, $id);
		} catch (FollowingNotConfiguredException $exception) {
			throw new HttpException(503, 'federation_not_configured', $exception->getMessage());
		} catch (DeliveryFailedException|HttpClientException) {
			throw new HttpException(
				502,
				'delivery_failed',
				'The remote inbox did not accept the Undo.',
			);
		} catch (ConfigurationException) {
			throw new HttpException(
				503,
				'instance_not_configured',
				'The instance URL is not configured.',
			);
		}

		if (!$removed) {
			throw HttpException::notFound('Followed actor not found.');
		}

		return Response::empty(204);
	}
}
