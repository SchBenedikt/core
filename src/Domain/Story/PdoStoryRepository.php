<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Story;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

use function array_map;
use function count;
use function implode;

/**
 * SQL story storage. Expiry is a filter in every active read and tombstone
 * rows make an early Delete survive a later Add replay.
 */
final class PdoStoryRepository implements StoryRepository
{
	private const COLUMNS = 'id, owner_id, is_local, object_id, caption, attachment_id, remote_media_url, created_at, expires_at, deleted_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Story $story): void
	{
		$existing = $this->findByObjectId($story->objectId);
		if ($existing !== null) {
			if ($existing->ownerId !== $story->ownerId) {
				throw new DatabaseException('The story object belongs to another owner.');
			}

			return;
		}

		try {
			$this->db->execute(
				'INSERT INTO ' . StorySchema::TABLE . ' (' . self::COLUMNS . ') VALUES ('
				. ' :id, :owner_id, :is_local, :object_id, :caption, :attachment_id, :remote_media_url,'
				. ' :created_at, :expires_at, :deleted_at)',
				$this->parameters($story),
			);
		} catch (Throwable $exception) {
			throw new DatabaseException('A story could not be stored.', 0, $exception);
		}
	}

	public function find(string $id): ?Story
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . StorySchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findByObjectId(string $objectId): ?Story
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . StorySchema::TABLE . ' WHERE object_id = :object_id',
			['object_id' => $objectId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findByAttachmentId(string $attachmentId): ?Story
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . StorySchema::TABLE
			. ' WHERE attachment_id = :attachment_id AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1',
			['attachment_id' => $attachmentId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function activeForOwners(array $ownerIds, DateTimeImmutable $now, int $limit): array
	{
		if ($ownerIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = ['now' => Time::format($now)];
		foreach ($ownerIds as $index => $ownerId) {
			$key = 'owner_' . $index;
			$placeholders[] = ':' . $key;
			$parameters[$key] = $ownerId;
		}

		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . StorySchema::TABLE
			. ' WHERE owner_id IN (' . implode(', ', $placeholders) . ')'
			. ' AND deleted_at IS NULL AND expires_at > :now'
			. ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
			$parameters,
		);

		return array_map(fn (array $row): Story => $this->hydrate($row), $rows);
	}

	public function expiredBefore(DateTimeImmutable $now, int $limit): array
	{
		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . StorySchema::TABLE
			. ' WHERE deleted_at IS NULL AND expires_at <= :now'
			. ' ORDER BY expires_at ASC, id ASC LIMIT ' . max(1, $limit),
			['now' => Time::format($now)],
		);

		return array_map(fn (array $row): Story => $this->hydrate($row), $rows);
	}

	public function softDelete(string $id, DateTimeImmutable $deletedAt): bool
	{
		$story = $this->find($id);
		if ($story === null || $story->isDeleted()) {
			return false;
		}

		$this->db->execute(
			'UPDATE ' . StorySchema::TABLE . ' SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL',
			['id' => $id, 'deleted_at' => Time::format($deletedAt)],
		);
		$this->markTombstone($story->objectId, $story->ownerId, $deletedAt);

		return true;
	}

	public function markTombstone(string $objectId, string $ownerId, DateTimeImmutable $deletedAt): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . StorySchema::TOMBSTONE_TABLE
				. ' (object_id, owner_id, deleted_at) VALUES (:object_id, :owner_id, :deleted_at)',
				[
					'object_id' => $objectId,
					'owner_id' => $ownerId,
					'deleted_at' => Time::format($deletedAt),
				],
			);
		} catch (Throwable) {
			$this->db->execute(
				'UPDATE ' . StorySchema::TOMBSTONE_TABLE . ' SET deleted_at = :deleted_at'
				. ' WHERE object_id = :object_id AND owner_id = :owner_id',
				[
					'object_id' => $objectId,
					'owner_id' => $ownerId,
					'deleted_at' => Time::format($deletedAt),
				],
			);
		}
	}

	public function isTombstoned(string $objectId, string $ownerId): bool
	{
		$row = $this->db->fetchOne(
			'SELECT 1 AS present FROM ' . StorySchema::TOMBSTONE_TABLE
			. ' WHERE object_id = :object_id AND owner_id = :owner_id',
			['object_id' => $objectId, 'owner_id' => $ownerId],
		);

		return $row !== null;
	}

	public function recordView(string $storyId, string $viewerId, DateTimeImmutable $viewedAt): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . StorySchema::VIEW_TABLE
				. ' (story_id, viewer_id, viewed_at) VALUES (:story_id, :viewer_id, :viewed_at)',
				[
					'story_id' => $storyId,
					'viewer_id' => $viewerId,
					'viewed_at' => Time::format($viewedAt),
				],
			);
		} catch (Throwable) {
			// The first receipt wins; a repeated view is still a no-op.
		}
	}

	public function viewers(string $storyId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT viewer_id, viewed_at FROM ' . StorySchema::VIEW_TABLE
			. ' WHERE story_id = :story_id ORDER BY viewed_at DESC, viewer_id ASC',
			['story_id' => $storyId],
		);

		return array_map(static fn (array $row): array => [
			'viewer_id' => (string) $row['viewer_id'],
			'viewed_at' => (string) $row['viewed_at'],
		], $rows);
	}

	public function saveReaction(StoryReaction $reaction): void
	{
		$key = [
			'story_id' => $reaction->storyId,
			'user_id' => $reaction->userId,
			'emoji' => $reaction->emoji,
		];
		$parameters = $key + ['created_at' => Time::format($reaction->createdAt)];

		$existing = $this->db->fetchOne(
			'SELECT 1 AS present FROM ' . StorySchema::REACTION_TABLE
			. ' WHERE story_id = :story_id AND user_id = :user_id AND emoji = :emoji',
			$key,
		);
		if ($existing !== null) {
			$this->db->execute(
				'UPDATE ' . StorySchema::REACTION_TABLE . ' SET created_at = :created_at'
				. ' WHERE story_id = :story_id AND user_id = :user_id AND emoji = :emoji',
				$parameters,
			);

			return;
		}

		try {
			$this->db->execute(
				'INSERT INTO ' . StorySchema::REACTION_TABLE
				. ' (story_id, user_id, emoji, created_at) VALUES (:story_id, :user_id, :emoji, :created_at)',
				$parameters,
			);
		} catch (Throwable $exception) {
			throw new DatabaseException('A story reaction could not be stored.', 0, $exception);
		}
	}

	public function removeReaction(string $storyId, string $userId, string $emoji): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . StorySchema::REACTION_TABLE
			. ' WHERE story_id = :story_id AND user_id = :user_id AND emoji = :emoji',
			['story_id' => $storyId, 'user_id' => $userId, 'emoji' => $emoji],
		) > 0;
	}

	public function reactions(string $storyId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT story_id, user_id, emoji, created_at FROM ' . StorySchema::REACTION_TABLE
			. ' WHERE story_id = :story_id ORDER BY created_at DESC, user_id ASC',
			['story_id' => $storyId],
		);

		return array_map(fn (array $row): StoryReaction => new StoryReaction(
			(string) $row['story_id'],
			(string) $row['user_id'],
			(string) $row['emoji'],
			Time::parse((string) $row['created_at']),
		), $rows);
	}

	public function saveReply(StoryReply $reply): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . StorySchema::REPLY_TABLE
				. ' (id, story_id, author_id, content, created_at) VALUES ('
				. ' :id, :story_id, :author_id, :content, :created_at)',
				[
					'id' => $reply->id,
					'story_id' => $reply->storyId,
					'author_id' => $reply->authorId,
					'content' => $reply->content,
					'created_at' => Time::format($reply->createdAt),
				],
			);
		} catch (Throwable $exception) {
			throw new DatabaseException('A story reply could not be stored.', 0, $exception);
		}
	}

	public function replies(string $storyId, int $limit): array
	{
		$rows = $this->db->fetchAll(
			'SELECT id, story_id, author_id, content, created_at FROM ' . StorySchema::REPLY_TABLE
			. ' WHERE story_id = :story_id ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
			['story_id' => $storyId],
		);

		return array_map(fn (array $row): StoryReply => new StoryReply(
			(string) $row['id'],
			(string) $row['story_id'],
			(string) $row['author_id'],
			(string) $row['content'],
			Time::parse((string) $row['created_at']),
		), $rows);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(Story $story): array
	{
		return [
			'id' => $story->id,
			'owner_id' => $story->ownerId,
			'is_local' => $story->local ? 1 : 0,
			'object_id' => $story->objectId,
			'caption' => $story->caption,
			'attachment_id' => $story->attachmentId,
			'remote_media_url' => $story->remoteMediaUrl,
			'created_at' => Time::format($story->createdAt),
			'expires_at' => Time::format($story->expiresAt),
			'deleted_at' => $story->deletedAt === null ? null : Time::format($story->deletedAt),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Story
	{
		return new Story(
			(string) $row['id'],
			(string) $row['owner_id'],
			(int) $row['is_local'] === 1,
			(string) $row['object_id'],
			(string) $row['caption'],
			Time::parse((string) $row['created_at']),
			Time::parse((string) $row['expires_at']),
			$row['attachment_id'] === null ? null : (string) $row['attachment_id'],
			$row['remote_media_url'] === null ? null : (string) $row['remote_media_url'],
			$row['deleted_at'] === null ? null : Time::parse((string) $row['deleted_at']),
		);
	}
}
