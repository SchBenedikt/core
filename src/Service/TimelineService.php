<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\TimelineEntry;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reading timelines.
 *
 * Limits are clamped rather than rejected: a client asking for 500 posts gets
 * 40. Cursor timestamps must be canonical. The optional ID breaks ties for
 * posts published in the same second. Silently ignoring a bad cursor would
 * hand the client the first page again and it would loop.
 */
final class TimelineService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public function __construct(
		private readonly PostRepository $posts,
		private readonly HomeTimelineRepository $homeTimeline,
		private readonly ?ModerationPolicy $moderation = null,
	) {
	}

	/**
	 * @return list<TimelineEntry>
	 *
	 * @throws InvalidCursorException
	 */
	public function home(
		string $userId,
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
		?string $beforeId = null,
	): array
	{
		return array_values(array_filter(
			$this->homeTimeline->forUser($userId, $this->clamp($limit), $this->cursor($before, $beforeId)),
			fn (TimelineEntry $entry): bool => $this->moderation?->allowsActor($entry->authorId) !== false
				&& ($entry->objectAuthorId === null || $this->moderation?->allowsActor($entry->objectAuthorId) !== false),
		));
	}

	/**
	 * @return list<Post>
	 *
	 * @throws InvalidCursorException
	 */
	public function public(
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
		?string $beforeId = null,
	): array
	{
		$cursor = $this->cursor($before, $beforeId);

		return $this->onlyAllowed($this->posts->publicTimeline(
			$this->clamp($limit),
			$cursor instanceof TimelineCursor ? $cursor->publishedAt : $cursor,
			$cursor instanceof TimelineCursor ? $cursor->id : null,
		));
	}

	/**
	 * Public posts carrying exactly one hashtag, newest first.
	 *
	 * @return list<Post>
	 *
	 * @throws InvalidHashtagException when the tag is empty or malformed
	 * @throws InvalidCursorException  when the cursor is malformed
	 */
	public function tag(
		string $tag,
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
		?string $beforeId = null,
	): array
	{
		$normalized = Hashtags::normalize($tag);
		if ($normalized === null) {
			throw new InvalidHashtagException($tag);
		}

		$cursor = $this->cursor($before, $beforeId);

		return $this->onlyAllowed($this->posts->hashtagTimeline(
			$normalized,
			$this->clamp($limit),
			$cursor instanceof TimelineCursor ? $cursor->publishedAt : $cursor,
			$cursor instanceof TimelineCursor ? $cursor->id : null,
		));
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
	 * @throws InvalidCursorException when the cursor is malformed
	 */
	public function followedTags(
		array $hashtags,
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
		?string $beforeId = null,
	): array {
		$cursor = $this->cursor($before, $beforeId);

		return $this->onlyAllowed($this->posts->followedHashtagTimeline(
			$hashtags,
			$this->clamp($limit),
			$cursor instanceof TimelineCursor ? $cursor->publishedAt : $cursor,
			$cursor instanceof TimelineCursor ? $cursor->id : null,
		));
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<Post>
	 */
	private function onlyAllowed(array $posts): array
	{
		if ($this->moderation === null) {
			return $posts;
		}

		return array_values(array_filter(
			$posts,
			fn (Post $post): bool => $this->moderation->allowsActor($post->authorId),
		));
	}

	public function clamp(int $limit): int
	{
		return max(1, min(self::MAX_LIMIT, $limit));
	}

	/**
	 * @throws InvalidCursorException
	 */
	private function cursor(?string $before, ?string $beforeId = null): DateTimeImmutable|TimelineCursor|null
	{
		if ($before === null || trim($before) === '') {
			if ($beforeId !== null && trim($beforeId) !== '') {
				throw new InvalidCursorException($beforeId);
			}

			return null;
		}

		try {
			$timestamp = Time::parse(trim($before));
			if ($beforeId === null || trim($beforeId) === '') {
				return $timestamp;
			}

			return new TimelineCursor($timestamp, trim($beforeId));
		} catch (InvalidArgumentException) {
			throw new InvalidCursorException($before);
		}
	}
}
