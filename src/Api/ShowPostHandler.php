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
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ReactionService;

/**
 * `GET /api/v1/posts/{id}`
 */
final class ShowPostHandler implements Handler
{
	public function __construct(
		private readonly PostService $posts,
		private readonly ?MediaService $media = null,
		private readonly ?ReactionService $reactions = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$id = (string) $request->attribute('id', '');

		try {
			$post = $this->posts->getVisible($id, $request->userId);
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		}

		$presented = $this->media === null ? $post->toArray() : $this->media->presentPost($post);

		// Counts and viewer state live with the interaction service so the
		// neutral detail and the Mastodon status cannot disagree about them.
		if ($this->reactions !== null) {
			$presented['interactions'] = $this->reactions->interactionsFor($post, $request->userId);
		}

		return Response::json(['post' => $presented]);
	}
}
