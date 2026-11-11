<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
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
	) {
	}

	/**
	 * @return list<Post>
	 *
	 * @throws InvalidCursorException
	 */
	public function home(string $userId, int $limit = self::DEFAULT_LIMIT, ?string $before = null): array
	{
		return $this->posts->homeTimeline($userId, $this->clamp($limit), $this->cursor($before));
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
