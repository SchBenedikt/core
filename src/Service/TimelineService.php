<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reading timelines.
 *
 * Limits are clamped rather than rejected: a client asking for 500 posts gets
 * 40. A cursor that is not a timestamp is an error, because silently ignoring
 * it would hand the client the first page again and it would loop.
 */
final class TimelineService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public function __construct(
		private readonly PostRepository $posts,
		private readonly HomeTimelineRepository $homeTimeline,
	) {
	}

	/**
	 * @return list<TimelineEntry>
	 *
	 * @throws InvalidCursorException
	 */
	public function home(string $userId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		return $this->homeTimeline->forUser($userId, $this->clamp($limit), $this->cursor($before));
	}

	/**
	 * @return list<Post>
	 *
	 * @throws InvalidCursorException
	 */
	public function public(int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		return $this->posts->publicTimeline($this->clamp($limit), $this->cursor($before));
	}

	/**
	 * Public posts carrying exactly one hashtag, newest first.
	 *
	 * @return list<Post>
	 *
	 * @throws InvalidHashtagException when the tag is empty or malformed
	 * @throws InvalidCursorException  when `before` is not a timestamp
	 */
	public function tag(string $tag, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		$normalized = Hashtags::normalize($tag);
		if ($normalized === null) {
			throw new InvalidHashtagException($tag);
		}

		return $this->posts->hashtagTimeline($normalized, $this->clamp($limit), $this->cursor($before));
	}

	/**
	 * Public posts carrying any of the caller's followed tags.
	 *
	 * The tag list is already normalized and owned by the caller. An empty
	 * list is an empty timeline, not an error.
	 *
	 * @param list<string> $hashtags
	 *
	 * @return list<Post>
	 *
	 * @throws InvalidCursorException when `before` is not a timestamp
	 */
	public function followedTags(
		array $hashtags,
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
	): array {
		return $this->posts->followedHashtagTimeline(
			$hashtags,
			$this->clamp($limit),
			$this->cursor($before),
		);
	}

	public function clamp(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	/**
	 * @throws InvalidCursorException
	 */
	private function cursor(?string $before): ?DateTimeImmutable
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		try {
			return Time::parse(trim($before));
		} catch (InvalidArgumentException) {
			throw new InvalidCursorException($before);
		}
	}
}
