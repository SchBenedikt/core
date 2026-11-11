<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FollowerService;

/**
 * `DELETE /api/v1/followers/{id}`
 *
 * Forgets a follower and therefore stops delivering to its inbox. A follower
 * of a different account is answered with 404 rather than 403: the id alone
 * must not reveal that another account has this follower.
 */
final class UnfollowHandler implements Handler
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

		$id = (string) $request->attribute('id', '');
		if ($id === '') {
			throw HttpException::notFound('Follower not found.');
		}

		if (!$this->followers->unfollow($userId, $id)) {
			throw HttpException::notFound('Follower not found.');
		}

		return Response::empty(204);
	}
}
