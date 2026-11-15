<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Story;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_filter;
use function array_slice;
use function array_values;
use function in_array;
use function usort;

/**
 * In-memory story adapter with the same expiry and owner boundaries as PDO.
 */
final class InMemoryStoryRepository implements StoryRepository
{
	/** @var array<string, Story> */
	private array $stories = [];

	/** @var array<string, array<string, DateTimeImmutable>> */
	private array $views = [];

	/** @var array<string, array<string, StoryReaction>> */
	private array $reactions = [];

	/** @var array<string, list<StoryReply>> */
	private array $replies = [];

	/** @var array<string, true> */
	private array $tombstones = [];

	public function insert(Story $story): void
	{
		foreach ($this->stories as $stored) {
			if ($stored->objectId === $story->objectId) {
				if ($stored->ownerId !== $story->ownerId) {
					throw new \AlohaSocial\Core\Platform\Db\DatabaseException('The story object belongs to another owner.');
				}

				return;
			}
		}

		$this->stories[$story->id] = $story;
	}

	public function find(string $id): ?Story
	{
		return $this->stories[$id] ?? null;
	}

	public function findByObjectId(string $objectId): ?Story
	{
		foreach ($this->stories as $story) {
			if ($story->objectId === $objectId) {
				return $story;
			}
		}

		return null;
	}

	public function findByAttachmentId(string $attachmentId): ?Story
	{
		foreach ($this->stories as $story) {
			if ($story->attachmentId === $attachmentId && !$story->isDeleted()) {
				return $story;
			}
		}

		return null;
	}

	public function activeForOwners(array $ownerIds, DateTimeImmutable $now, int $limit): array
	{
		$stories = array_values(array_filter(
			$this->stories,
			static fn (Story $story): bool => !$story->isDeleted()
				&& !$story->isExpiredAt($now)
				&& in_array($story->ownerId, $ownerIds, true),
		));
		usort($stories, static fn (Story $a, Story $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

		return array_slice($stories, 0, max(1, $limit));
	}

	public function expiredBefore(DateTimeImmutable $now, int $limit): array
	{
		$stories = array_values(array_filter(
			$this->stories,
			static fn (Story $story): bool => !$story->isDeleted() && $story->isExpiredAt($now),
		));
		usort($stories, static fn (Story $a, Story $b): int => [$a->expiresAt, $a->id] <=> [$b->expiresAt, $b->id]);

		return array_slice($stories, 0, max(1, $limit));
	}

	public function softDelete(string $id, DateTimeImmutable $deletedAt): bool
	{
		$story = $this->stories[$id] ?? null;
		if ($story === null || $story->isDeleted()) {
			return false;
		}

		$this->stories[$id] = $story->withDeletedAt($deletedAt);
		$this->tombstones[$story->objectId . "\0" . $story->ownerId] = true;

		return true;
	}

	public function markTombstone(string $objectId, string $ownerId, DateTimeImmutable $deletedAt): void
	{
		$this->tombstones[$objectId . "\0" . $ownerId] = true;
	}

	public function isTombstoned(string $objectId, string $ownerId): bool
	{
		return isset($this->tombstones[$objectId . "\0" . $ownerId]);
	}

	public function recordView(string $storyId, string $viewerId, DateTimeImmutable $viewedAt): void
	{
		$this->views[$storyId][$viewerId] ??= $viewedAt;
	}

	public function viewers(string $storyId): array
	{
		$rows = [];
		foreach ($this->views[$storyId] ?? [] as $viewerId => $viewedAt) {
			$rows[] = ['viewer_id' => $viewerId, 'viewed_at' => Time::format($viewedAt)];
		}
		usort($rows, static fn (array $a, array $b): int => strcmp($b['viewed_at'], $a['viewed_at']));

		return $rows;
	}

	public function saveReaction(StoryReaction $reaction): void
	{
		$this->reactions[$reaction->storyId][$reaction->userId . "\0" . $reaction->emoji] = $reaction;
	}

	public function removeReaction(string $storyId, string $userId, string $emoji): bool
	{
		$key = $userId . "\0" . $emoji;
		$exists = isset($this->reactions[$storyId][$key]);
		unset($this->reactions[$storyId][$key]);

		return $exists;
	}

	public function reactions(string $storyId): array
	{
		$rows = array_values($this->reactions[$storyId] ?? []);
		usort($rows, static fn (StoryReaction $a, StoryReaction $b): int => [$b->createdAt, $a->userId] <=> [$a->createdAt, $b->userId]);

		return $rows;
	}

	public function saveReply(StoryReply $reply): void
	{
		$this->replies[$reply->storyId][] = $reply;
	}

	public function replies(string $storyId, int $limit): array
	{
		$rows = $this->replies[$storyId] ?? [];
		usort($rows, static fn (StoryReply $a, StoryReply $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

		return array_slice($rows, 0, max(1, $limit));
	}
}
