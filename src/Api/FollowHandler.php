<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FollowActor;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\ValidationException;

use function is_string;

/**
 * `POST /api/v1/followers`
 *
 * Records a remote actor as a follower of the signed-in account. Accepts
 * `actor` and `inbox`; both are absolute URLs taken from the actor document
 * the host resolved. The call is idempotent: a repeated Follow answers 200
 * with the existing follower instead of creating a second one.
 */
final class FollowHandler implements Handler
{
	public function __construct(
		private readonly FollowerService $followers,
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
			$result = $this->followers->follow(new FollowActor($userId, $actor, $inbox));
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		$follower = $result->follower;

		return Response::json(
			['follower' => $follower->toArray()],
			$result->created ? 201 : 200,
			$result->created
				? ['Location' => ApiVersion::BASE_PATH . '/followers/' . rawurlencode($follower->id)]
				: [],
		);
	}
}
