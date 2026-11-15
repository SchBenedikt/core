<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Story;

use DateTimeImmutable;

/**
 * Durable stories, their private receipts, reactions and replies.
 *
 * Every read that can reach a viewer is bounded. An expired row is filtered
 * by `expires_at` in the query, not only in PHP, so a restarted worker cannot
 * resurrect it by forgetting an in-memory expiry set.
 */
interface StoryRepository
{
	public function insert(Story $story): void;

	public function find(string $id): ?Story;

	public function findByObjectId(string $objectId): ?Story;

	public function findByAttachmentId(string $attachmentId): ?Story;

	/**
	 * @return list<Story> active stories of the given owners, newest first
	 */
	public function activeForOwners(array $ownerIds, DateTimeImmutable $now, int $limit): array;

	/**
	 * @return list<Story> expired but not yet marked deleted
	 */
	public function expiredBefore(DateTimeImmutable $now, int $limit): array;

	public function softDelete(string $id, DateTimeImmutable $deletedAt): bool;

	/**
	 * A Delete that arrives before its Add is remembered, so a later replay
	 * of the Add cannot bring the story back.
	 */
	public function markTombstone(string $objectId, string $ownerId, DateTimeImmutable $deletedAt): void;

	public function isTombstoned(string $objectId, string $ownerId): bool;

	public function recordView(string $storyId, string $viewerId, DateTimeImmutable $viewedAt): void;

	/**
	 * @return list<array{viewer_id: string, viewed_at: string}>
	 */
	public function viewers(string $storyId): array;

	public function saveReaction(StoryReaction $reaction): void;

	public function removeReaction(string $storyId, string $userId, string $emoji): bool;

	/**
	 * @return list<StoryReaction>
	 */
	public function reactions(string $storyId): array;

	public function saveReply(StoryReply $reply): void;

	/**
	 * @return list<StoryReply> newest first
	 */
	public function replies(string $storyId, int $limit): array;
}
