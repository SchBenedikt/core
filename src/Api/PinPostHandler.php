<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\PinService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `PUT /api/v1/pins/{postId}`
 */
final class PinPostHandler implements Handler
{
	public function __construct(
		private readonly PinService $pins,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$pin = $this->pins->pin($userId, (string) $request->attribute('postId', ''));
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (PostAccessDeniedException) {
			throw HttpException::forbidden('You can only pin your own posts.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['pin' => $pin->toArray()], 200, ['Cache-Control' => 'private, no-store']);
	}
}
