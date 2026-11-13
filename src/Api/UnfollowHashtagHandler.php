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
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\InvalidHashtagException;

/**
 * `DELETE /api/v1/followed_tags/{tag}`.
 *
 * Unfollowing an absent tag is a successful no-op, so a retry is safe. The
 * response echoes the normalized tag rather than the raw path value.
 */
final class UnfollowHashtagHandler implements Handler
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
			$tag = $this->followedTags->unfollow(
				$userId,
				(string) $request->attribute('tag', ''),
			);
		} catch (InvalidHashtagException $exception) {
			throw HttpException::badRequest('invalid_tag', $exception->getMessage());
		}

		return Response::json(['tag' => $tag]);
	}
}
