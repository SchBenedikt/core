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
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * `GET /api/v1/posts/archived`
 */
final class ArchivedPostsHandler implements Handler
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

		$before = $request->queryParam('before');
		$cursor = null;
		if ($before !== null && trim($before) !== '') {
			try {
				$cursor = Time::parse(trim($before));
			} catch (InvalidArgumentException) {
				throw new HttpException(400, 'invalid_cursor', 'The cursor is not a timestamp.');
			}
		}

		$entries = $this->posts->archived(
			$userId,
			$request->queryInt('limit', 20, 1, 40),
			$cursor instanceof DateTimeImmutable ? $cursor : null,
			$request->queryParam('before_id'),
		);

		return Response::json([
			'posts' => array_map(static fn ($post): array => $post->toArray(), $entries),
		], 200, ['Cache-Control' => 'private, no-store']);
	}
}
