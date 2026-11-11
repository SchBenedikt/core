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
use AlohaSocial\Core\Service\PostService;

/**
 * `DELETE /api/v1/posts/{id}`
 *
 * Ownership is checked in the service. A post somebody else wrote answers 403
 * rather than 404: the post is public, so pretending it does not exist would
 * only change the status code, not the information.
 */
final class DeletePostHandler implements Handler
{
	public function __construct(
		private readonly PostService $posts,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$id = (string) $request->attribute('id', '');

		try {
			$this->posts->delete($userId, $id);
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (PostAccessDeniedException) {
			throw HttpException::forbidden('You can only delete your own posts.');
		}

		return Response::empty(204);
	}
}
