<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtag;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagRepository;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function is_string;
use function mb_strlen;
use function trim;

/**
 * Following, unfollowing and listing private hashtags.
 *
 * Tags use the same normalization as post content, so `#DevOps`, `devops`
 * and a duplicate follow resolve to one row. The follow limit keeps the
 * exact-match timeline query bounded; the cursor mirrors the post timelines.
 */
final class FollowedHashtagService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public const MAX_FOLLOWED = 100;

	public function __construct(
		private readonly FollowedHashtagRepository $follows,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{0: FollowedHashtag, 1: bool} the follow and whether it is new
	 *
	 * @throws FollowedHashtagLimitException
	 * @throws InvalidHashtagException
	 */
	public function follow(string $userId, mixed $tag): array
	{
		$userId = $this->account($userId);
		$tag = $this->tag($tag);

		$existing = $this->follows->find($userId, $tag);
		if ($existing !== null) {
			return [$existing, false];
		}

		if ($this->follows->count($userId) >= self::MAX_FOLLOWED) {
			throw new FollowedHashtagLimitException(self::MAX_FOLLOWED);
		}

		$createdAt = Time::toSecondPrecision($this->clock->now());
		$created = $this->follows->follow($userId, $tag, $createdAt);

		return [
			$this->follows->find($userId, $tag) ?? new FollowedHashtag($userId, $tag, $createdAt),
			$created,
		];
	}

	/**
	 * @return string the normalized tag
	 *
	 * @throws InvalidHashtagException
	 */
	public function unfollow(string $userId, mixed $tag): string
	{
		$userId = $this->account($userId);
		$tag = $this->tag($tag);
		$this->follows->unfollow($userId, $tag);

		return $tag;
	}

	/**
	 * @return list<string>
	 */
	public function tags(string $userId): array
	{
		return $this->follows->tags($this->account($userId));
	}

	/**
	 * @return list<FollowedHashtag>
	 *
	 * @throws InvalidCursorException
	 * @throws InvalidHashtagException
	 */
	public function page(
		string $userId,
		int $limit = self::DEFAULT_LIMIT,
		?string $before = null,
		?string $beforeTag = null,
	): array {
		$userId = $this->account($userId);
		$beforeTag = $beforeTag === null || trim($beforeTag) === ''
			? null
			: $this->tag($beforeTag);

		return $this->follows->page(
			$userId,
			max(1, min(self::MAX_LIMIT, $limit)),
			$this->cursor($before),
			$beforeTag,
		);
	}

	/**
	 * @throws InvalidHashtagException
	 */
	private function tag(mixed $tag): string
	{
		$normalized = is_string($tag) ? Hashtags::normalize($tag) : null;
		if ($normalized === null) {
			throw new InvalidHashtagException(is_string($tag) ? $tag : '');
		}

		return $normalized;
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

	private function account(string $userId): string
	{
		$userId = trim($userId);
		if ($userId === '' || mb_strlen($userId) > FollowedHashtag::MAX_ACCOUNT_LENGTH) {
			throw new InvalidHashtagException('');
		}

		return $userId;
	}
}
