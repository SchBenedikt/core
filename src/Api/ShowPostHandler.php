<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ReactionService;

/**
 * `GET /api/v1/posts/{id}?include_filtered=`
 *
 * A hidden post answers 404 for the reader whose rule hides it: returning a
 * redacted body would still confirm the post, its author and its counts. A
 * warned post is returned with its evidence and without the text until
 * `include_filtered` is present.
 */
final class ShowPostHandler implements Handler
{
	public function __construct(
		private readonly PostService $posts,
		private readonly ?MediaService $media = null,
		private readonly ?ReactionService $reactions = null,
		private readonly ?FilterService $filters = null,
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

		$match = $request->userId === null || $this->filters === null
			? null
			: $this->filters->matchPost(
				$request->userId,
				FilterContext::Thread,
				$post,
				$this->media?->altTextsForPosts([$post])[$post->id] ?? [],
			);

		if ($match?->action === FilterAction::Hide) {
			throw HttpException::notFound('Post not found.');
		}

		$presented = $this->media === null ? $post->toArray() : $this->media->presentPost($post);

		// Counts and viewer state live with the interaction service so the
		// neutral detail and the Mastodon status cannot disagree about them.
		if ($this->reactions !== null) {
			$presented['interactions'] = $this->reactions->interactionsFor($post, $request->userId);
		}

		if ($match !== null && $this->filters !== null) {
			$presented['filtered'] = [$this->filters->filterBlock($match)];
			if (!FilterRequestParser::includeFiltered($request)) {
				$presented = $this->filters->redact($presented);
			}
		}

		return Response::json(['post' => $presented]);
	}
}
