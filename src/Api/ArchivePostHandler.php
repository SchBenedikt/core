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
 * `POST /api/v1/posts/{id}/archive`
 */
final class ArchivePostHandler implements Handler
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

		try {
			$post = $this->posts->archive($userId, (string) $request->attribute('id', ''));
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (PostAccessDeniedException) {
			throw HttpException::forbidden('You can only archive your own posts.');
		}

		return Response::json(['post' => $post->toArray()], 200, ['Cache-Control' => 'private, no-store']);
	}
}
