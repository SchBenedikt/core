<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * `GET /api/v1/accounts/{id}/statuses?limit=&before=`
 *
 * The profile timeline of one local account. Unlike a scan of the public
 * timeline this reaches every post the viewer may see, so an older post does
 * not disappear just because newer posts pushed it off the public page.
 * Followers-only posts are included only for the author and accepted local
 * followers; anonymous readers get the public and unlisted rows.
 */
final class AccountStatusesHandler implements Handler
{
	public function __construct(
		private readonly PostRepository $posts,
		private readonly PostService $postService,
		private readonly ?MediaService $media = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$authorId = trim((string) $request->attribute('id', ''));
		if ($authorId === '') {
			throw HttpException::notFound('Account not found.');
		}

		$limit = $request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT);
		$before = $this->cursor($request->queryParam('before'));

		$fetch = min(TimelineService::MAX_LIMIT, $limit * 2);
		$rows = $this->posts->timelineForAuthors([$authorId], $fetch, $before);

		$visible = [];
		foreach ($rows as $post) {
			try {
				$visible[] = $this->postService->getVisible($post->id, $request->userId);
			} catch (PostNotFoundException) {
				// The repository already limited the audience; this keeps the
				// handler's answer identical to the single-post route.
			}
		}

		$visible = array_slice($visible, 0, $limit);
		$presented = $this->media === null
			? array_map(static fn ($post): array => $post->toArray(), $visible)
			: $this->media->presentPosts($visible);

		// Page from the last row the repository returned, not the last row the
		// viewer may see. A page that is entirely followers-only for an
		// anonymous reader must still lead to the older public posts.
		$last = $rows === [] ? null : $rows[array_key_last($rows)];

		return Response::json([
			'posts' => $presented,
			'next_before' => $last === null || count($rows) < $fetch ? null : Time::format($last->publishedAt),
		]);
	}

	private function cursor(?string $before): ?DateTimeImmutable
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		try {
			return Time::parse(trim($before));
		} catch (InvalidArgumentException) {
			throw HttpException::badRequest('invalid_cursor', 'The before cursor is not a timestamp.');
		}
	}
}
