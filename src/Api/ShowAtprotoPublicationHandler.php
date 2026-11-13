<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;

/**
 * `GET /api/v1/posts/{id}/atproto`
 *
 * Reads the local idempotency row, not the PDS. It therefore stays available
 * while publishing is disabled, and it is scoped to the post's author: the
 * post-to-record mapping belongs to the account that published it.
 */
final class ShowAtprotoPublicationHandler implements Handler
{
	public function __construct(
		private readonly AtprotoPublishService $service,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$postId = (string) $request->attribute('id', '');

		try {
			$publication = $this->service->publicationFor($userId, $postId);
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		}

		return Response::json(['publication' => $publication?->toArray()]);
	}
}
