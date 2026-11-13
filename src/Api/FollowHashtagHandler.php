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
use AlohaSocial\Core\Service\FollowedHashtagLimitException;
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\InvalidHashtagException;

/**
 * `POST /api/v1/followed_tags` with `{"tag": "devops"}`.
 *
 * Following is idempotent: a new row answers 201, an existing follow answers
 * 200 with its original timestamp. The tag may carry one leading `#`.
 */
final class FollowHashtagHandler implements Handler
{
	public function __construct(
		private readonly FollowedHashtagService $followedTags,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			[$follow, $created] = $this->followedTags->follow(
				$userId,
				$request->payload()['tag'] ?? null,
			);
		} catch (InvalidHashtagException $exception) {
			throw HttpException::badRequest('invalid_tag', $exception->getMessage());
		} catch (FollowedHashtagLimitException $exception) {
			throw HttpException::badRequest('followed_tag_limit', $exception->getMessage());
		}

		return Response::json($follow->toArray(), $created ? 201 : 200);
	}
}
