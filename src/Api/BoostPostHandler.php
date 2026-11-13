<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\BoostService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `POST /api/v1/posts/{id}/boost`
 *
 * Repeating the call answers 200 with the existing boost; a new boost
 * answers 201 with a Location. Only public and unlisted posts may be boosted.
 */
final class BoostPostHandler implements Handler
{
	public function __construct(
		private readonly BoostService $boosts,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$result = $this->boosts->boost($userId, (string) $request->attribute('id', ''));
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(
			['boost' => $result->boost->toArray()],
			$result->created ? 201 : 200,
			$result->created
				? ['Location' => ApiVersion::BASE_PATH . '/posts/' . rawurlencode($result->boost->postId)]
				: [],
		);
	}
}
