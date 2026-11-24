<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Support\Time;

/** Builds cursor metadata after applying reader-specific presentation filters. */
final class PostTimelinePage
{
	/**
	 * @param list<Post>                 $sourcePosts
	 * @param list<array<string, mixed>> $presentedPosts
	 * @param array<string, mixed>       $metadata
	 */
	public static function response(array $sourcePosts, array $presentedPosts, int $limit, int $fetchLimit, array $metadata = []): Response
	{
		$posts = array_slice($presentedPosts, 0, $limit);
		$hasMore = count($presentedPosts) > $limit || count($sourcePosts) >= $fetchLimit;
		$cursorPost = null;

		if ($hasMore && count($presentedPosts) > $limit) {
			$lastVisibleId = $posts[array_key_last($posts)]['id'] ?? null;
			foreach ($sourcePosts as $sourcePost) {
				if ($sourcePost->id === $lastVisibleId) {
					$cursorPost = $sourcePost;
					break;
				}
			}
		}

		if ($hasMore && $cursorPost === null && $sourcePosts !== []) {
			$cursorPost = $sourcePosts[array_key_last($sourcePosts)];
		}

		return Response::json($metadata + [
			'posts' => $posts,
			'has_more' => $hasMore,
			'next_before' => $cursorPost instanceof Post ? Time::format($cursorPost->publishedAt) : null,
			'next_before_id' => $cursorPost?->id,
		]);
	}
}
