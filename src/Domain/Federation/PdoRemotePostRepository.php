<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

/**
 * Remote-post storage in SQL.
 *
 * The primary key is the activity id, so replay protection survives a process
 * restart even when the activity log's retention window has passed. Timestamps
 * use the canonical fixed-width form and are ordered as text.
 */
final class PdoRemotePostRepository implements RemotePostRepository, RemoteDomainCount
{
	public const MAX_ATTACHMENTS = 10;

	private const COLUMNS = 'activity_id, object_id, object_author_id, actor_id, kind,'
		. ' object_type, object_metadata, quoted_object_id, in_reply_to_id, audience, content, content_warning,'
		. ' published_at, updated_at, deleted_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(RemotePost $post): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . RemotePostSchema::TABLE
				. ' (' . self::COLUMNS . ')'
				. ' VALUES (:activity_id, :object_id, :object_author_id, :actor_id, :kind,'
				. ' :object_type, :object_metadata, :quoted_object_id, :in_reply_to_id, :audience, :content, :content_warning,'
				. ' :published_at, :updated_at, :deleted_at)',
				$this->parameters($post),
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The remote post could not be stored.', 0, $exception);
		}
	}

	public function upsert(RemotePost $post): void
	{
		$parameters = $this->parameters($post);

		if ($this->findByActivityId($post->activityId) !== null) {
			$this->update($post->activityId, $parameters);

			return;
		}

		try {
			$this->insert($post);
		} catch (DatabaseException) {
			// A concurrent request may have inserted the same activity id
			// between the lookup and this insert; refresh that row instead.
			if ($this->findByActivityId($post->activityId) === null) {
				throw new DatabaseException('The remote post could not be stored.');
			}

			$this->update($post->activityId, $parameters);
		}
	}

	/**
	 * @return array<string, string|int|null>
	 */
	private function parameters(RemotePost $post): array
	{
		return [
			'activity_id' => $post->activityId,
			'object_id' => $post->objectId,
			'object_author_id' => $post->objectAuthorId,
			'actor_id' => $post->actorId,
			'kind' => $post->kind->value,
			'object_type' => $post->objectType->value,
			'object_metadata' => $post->metadata?->toJson(),
			'quoted_object_id' => $post->quotedObjectId,
			'in_reply_to_id' => $post->inReplyToId,
			'audience' => $post->audience?->value,
			'content' => $post->content,
			'content_warning' => $post->contentWarning,
			'published_at' => Time::format($post->publishedAt),
			'updated_at' => $post->updatedAt === null ? null : Time::format($post->updatedAt),
			'deleted_at' => $post->deletedAt === null ? null : Time::format($post->deletedAt),
		];
	}

	/**
	 * @param array<string, string|int|null> $parameters
	 */
	private function update(string $activityId, array $parameters): void
	{
		$this->db->execute(
			'UPDATE ' . RemotePostSchema::TABLE
			. ' SET object_id = :object_id, object_author_id = :object_author_id,'
			. ' actor_id = :actor_id, kind = :kind, object_type = :object_type,'
			. ' object_metadata = :object_metadata, quoted_object_id = :quoted_object_id,'
			. ' in_reply_to_id = :in_reply_to_id, audience = :audience, content = :content,'
			. ' content_warning = :content_warning, published_at = :published_at,'
			. ' updated_at = :updated_at, deleted_at = :deleted_at'
			. ' WHERE activity_id = :activity_id',
			['activity_id' => $activityId, ...$parameters],
		);
	}

	public function findByActivityId(string $activityId): ?RemotePost
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . RemotePostSchema::TABLE
			. ' WHERE activity_id = :activity_id',
			['activity_id' => $activityId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function findByActivityIds(array $activityIds): array
	{
		$unique = [];
		foreach ($activityIds as $activityId) {
			if (is_string($activityId) && $activityId !== '') {
				$unique[$activityId] = $activityId;
			}
		}
		$activityIds = array_values($unique);

		if ($activityIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [];
		foreach ($activityIds as $index => $activityId) {
			$name = 'activity_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $activityId;
		}

		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . RemotePostSchema::TABLE
			. ' WHERE activity_id IN (' . implode(', ', $placeholders) . ')',
			$parameters,
		);

		$posts = [];
		foreach ($rows as $row) {
			$post = $this->hydrate($row);
			$posts[$post->activityId] = $post;
		}

		return $posts;
	}

	public function findPostByObjectId(string $objectId): ?RemotePost
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . RemotePostSchema::TABLE
			. ' WHERE object_id = :object_id AND kind = :kind AND deleted_at IS NULL'
			. ' ORDER BY published_at DESC, activity_id DESC LIMIT 1',
			['object_id' => $objectId, 'kind' => RemotePostKind::Post->value],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function updatePostContent(
		string $objectId,
		string $actorId,
		string $content,
		?string $contentWarning,
		DateTimeImmutable $updatedAt,
	): bool {
		return $this->db->execute(
			'UPDATE ' . RemotePostSchema::TABLE
			. ' SET content = :content, content_warning = :content_warning, updated_at = :updated_at'
			. ' WHERE object_id = :object_id AND actor_id = :actor_id'
			. ' AND kind = :kind AND deleted_at IS NULL',
			[
				'object_id' => $objectId,
				'actor_id' => $actorId,
				'content' => $content,
				'content_warning' => $contentWarning,
				'updated_at' => Time::format($updatedAt),
				'kind' => RemotePostKind::Post->value,
			],
		) > 0;
	}

	public function softDeleteByActivityId(
		string $activityId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool {
		return $this->db->execute(
			'UPDATE ' . RemotePostSchema::TABLE
			. ' SET deleted_at = :deleted_at'
			. ' WHERE activity_id = :activity_id AND actor_id = :actor_id AND deleted_at IS NULL',
			[
				'activity_id' => $activityId,
				'actor_id' => $actorId,
				'deleted_at' => Time::format($deletedAt),
			],
		) > 0;
	}

	public function softDeletePostByObjectId(
		string $objectId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool {
		return $this->db->execute(
			'UPDATE ' . RemotePostSchema::TABLE
			. ' SET deleted_at = :deleted_at'
			. ' WHERE object_id = :object_id AND actor_id = :actor_id'
			. ' AND kind = :kind AND deleted_at IS NULL',
			[
				'object_id' => $objectId,
				'actor_id' => $actorId,
				'kind' => RemotePostKind::Post->value,
				'deleted_at' => Time::format($deletedAt),
			],
		) > 0;
	}

	public function timelineForActors(
		array $actorIds,
		int $limit,
		DateTimeImmutable|TimelineCursor|null $before = null,
	): array {
		$actorIds = array_values(array_unique($actorIds));
		if ($actorIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [];
		foreach ($actorIds as $index => $actorId) {
			$name = 'actor_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $actorId;
		}

		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . RemotePostSchema::TABLE
			. ' WHERE deleted_at IS NULL'
			. ' AND actor_id IN (' . implode(', ', $placeholders) . ')';

		if ($before instanceof TimelineCursor) {
			$sql .= ' AND (published_at < :before OR (published_at = :before_equal AND activity_id < :before_id))';
			$parameters['before'] = Time::format($before->publishedAt);
			$parameters['before_equal'] = Time::format($before->publishedAt);
			$parameters['before_id'] = $before->id;
		} elseif ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY published_at DESC, activity_id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): RemotePost => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
	}

	public function linkAttachments(string $activityId, array $attachmentIds): void
	{
		$attachmentIds = array_values(array_unique($attachmentIds));
		if (count($attachmentIds) > self::MAX_ATTACHMENTS) {
			throw new DatabaseException('Too many attachments for one remote post.');
		}

		try {
			$this->db->transaction(function (Connection $db) use ($activityId, $attachmentIds): void {
				$db->execute(
					'DELETE FROM ' . RemotePostSchema::ATTACHMENT_TABLE
					. ' WHERE activity_id = :activity_id',
					['activity_id' => $activityId],
				);

				foreach ($attachmentIds as $position => $attachmentId) {
					$db->execute(
						'INSERT INTO ' . RemotePostSchema::ATTACHMENT_TABLE
						. ' (activity_id, attachment_id, position) VALUES'
						. ' (:activity_id, :attachment_id, :position)',
						[
							'activity_id' => $activityId,
							'attachment_id' => $attachmentId,
							'position' => $position,
						],
					);
				}
			});
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The remote post attachments could not be linked.', 0, $exception);
		}
	}

	public function remoteDomainCount(): int
	{
		$rows = $this->db->fetchAll(
			'SELECT actor_id FROM ' . RemotePostSchema::TABLE . ' WHERE deleted_at IS NULL',
		);

		$domains = [];
		foreach ($rows as $row) {
			$host = parse_url((string) $row['actor_id'], PHP_URL_HOST);
			if (is_string($host) && $host !== '') {
				$domains[strtolower($host)] = true;
			}
		}

		return count($domains);
	}

	public function postsForAttachment(string $attachmentId): array
	{
		try {
			$rows = $this->db->fetchAll(
				'SELECT p.* FROM ' . RemotePostSchema::TABLE . ' p'
				. ' JOIN ' . RemotePostSchema::ATTACHMENT_TABLE . ' l ON l.activity_id = p.activity_id'
				. ' WHERE l.attachment_id = :attachment_id'
				. ' ORDER BY p.published_at DESC, p.activity_id DESC',
				['attachment_id' => $attachmentId],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('Remote posts for an attachment could not be read.', 0, $exception);
		}

		return array_map(fn (array $row): RemotePost => $this->hydrate($row), $rows);
	}

	public function isAttachmentLinked(string $attachmentId): bool
	{
		try {
			return (int) $this->db->fetchValue(
				'SELECT COUNT(*) FROM ' . RemotePostSchema::ATTACHMENT_TABLE
				. ' WHERE attachment_id = :attachment_id',
				['attachment_id' => $attachmentId],
			) > 0;
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('Remote attachment links could not be read.', 0, $exception);
		}
	}

	public function attachmentIdsFor(array $activityIds): array
	{
		$activityIds = array_values(array_unique($activityIds));
		if ($activityIds === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [];
		foreach ($activityIds as $index => $activityId) {
			$name = 'activity_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $activityId;
		}

		$rows = $this->db->fetchAll(
			'SELECT activity_id, attachment_id FROM ' . RemotePostSchema::ATTACHMENT_TABLE
			. ' WHERE activity_id IN (' . implode(', ', $placeholders) . ')'
			. ' ORDER BY activity_id, position',
			$parameters,
		);

		$result = [];
		foreach ($rows as $row) {
			$result[(string) $row['activity_id']][] = (string) $row['attachment_id'];
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): RemotePost
	{
		return new RemotePost(
			activityId: (string) $row['activity_id'],
			objectId: (string) $row['object_id'],
			actorId: (string) $row['actor_id'],
			kind: RemotePostKind::from((string) $row['kind']),
			content: (string) $row['content'],
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			publishedAt: Time::parse((string) $row['published_at']),
			objectAuthorId: $row['object_author_id'] === null ? null : (string) $row['object_author_id'],
			deletedAt: $row['deleted_at'] === null ? null : Time::parse((string) $row['deleted_at']),
			updatedAt: ($row['updated_at'] ?? null) === null ? null : Time::parse((string) $row['updated_at']),
			objectType: RemoteObjectType::tryFrom((string) ($row['object_type'] ?? 'note')) ?? RemoteObjectType::Note,
			metadata: RemoteObjectMetadata::fromStored(
				isset($row['object_metadata']) && is_string($row['object_metadata']) ? $row['object_metadata'] : null,
			),
			quotedObjectId: ($row['quoted_object_id'] ?? null) === null ? null : (string) $row['quoted_object_id'],
			inReplyToId: ($row['in_reply_to_id'] ?? null) === null ? null : (string) $row['in_reply_to_id'],
			audience: ($row['audience'] ?? null) === null ? null : Visibility::tryFrom((string) $row['audience']),
		);
	}
}
