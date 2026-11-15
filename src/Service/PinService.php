<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostPin;
use AlohaSocial\Core\Domain\Post\PostPinRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;

/**
 * Profile pins: at most five own public posts, ordered explicitly.
 *
 * A pin is a reference, not a copy. The post keeps its id, visibility and
 * federation state; deleting the pin never touches the post, and deleting or
 * archiving the post removes the reference.
 */
final class PinService
{
	public function __construct(
		private readonly PostRepository $posts,
		private readonly PostPinRepository $pins,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function forOwner(string $ownerId): array
	{
		$result = [];
		foreach ($this->pins->forOwner($ownerId) as $pin) {
			$post = $this->posts->find($pin->postId);
			if ($post === null || $post->isDeleted() || $post->isArchived()) {
				continue;
			}

			$result[] = [
				'post' => $post->toArray(),
				'position' => $pin->position,
				'pinned_at' => Time::format($pin->pinnedAt),
			];
		}

		return $result;
	}

	/**
	 * @return list<Post>
	 */
	public function featuredPosts(string $ownerId): array
	{
		$posts = [];
		foreach ($this->pins->forOwner($ownerId) as $pin) {
			$post = $this->posts->find($pin->postId);
			if ($post !== null
				&& !$post->isDeleted()
				&& !$post->isArchived()
				&& $post->authorId === $ownerId
				&& $post->visibility === Visibility::Public
			) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * @throws PostNotFoundException
	 * @throws PostAccessDeniedException
	 * @throws ValidationException
	 */
	public function pin(string $ownerId, string $postId): PostPin
	{
		$existing = $this->pins->find($ownerId, $postId);
		if ($existing !== null) {
			return $existing;
		}

		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted()) {
			throw new PostNotFoundException($postId);
		}

		if ($post->authorId !== $ownerId) {
			throw new PostAccessDeniedException($postId, $ownerId);
		}

		if ($post->visibility !== Visibility::Public) {
			throw new ValidationException(['post_id' => 'Only a public post can be pinned.']);
		}

		if ($post->isArchived()) {
			throw new ValidationException(['post_id' => 'An archived post cannot be pinned.']);
		}

		if ($this->pins->count($ownerId) >= PostPin::MAX_PINS) {
			throw new ValidationException(['pins' => 'At most five posts can be pinned.']);
		}

		$position = $this->freePosition($ownerId);
		try {
			$pin = new PostPin($ownerId, $postId, $position, Time::toSecondPrecision($this->clock->now()));
			$this->pins->insert($pin);
		} catch (DatabaseException) {
			// A concurrent pin may have won the slot. An existing row is a
			// successful duplicate; otherwise the caller sees the same 422.
			$existing = $this->pins->find($ownerId, $postId);
			if ($existing !== null) {
				return $existing;
			}

			throw new ValidationException(['pins' => 'At most five posts can be pinned.']);
		}

		return $pin;
	}

	public function unpin(string $ownerId, string $postId): void
	{
		$this->pins->remove($ownerId, $postId);
	}

	public function isPinned(string $ownerId, string $postId): bool
	{
		return $this->pins->find($ownerId, $postId) !== null;
	}

	private function freePosition(string $ownerId): int
	{
		$used = [];
		foreach ($this->pins->forOwner($ownerId) as $pin) {
			$used[$pin->position] = true;
		}

		for ($position = 1; $position <= PostPin::MAX_PINS; $position++) {
			if (!isset($used[$position])) {
				return $position;
			}
		}

		throw new ValidationException(['pins' => 'At most five posts can be pinned.']);
	}
}
