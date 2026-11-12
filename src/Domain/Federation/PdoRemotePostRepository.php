<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
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
final class PdoRemotePostRepository implements RemotePostRepository
{
	private const COLUMNS = 'activity_id, object_id, object_author_id, actor_id, kind,'
		. ' content, content_warning, published_at, deleted_at';

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
				. ' :content, :content_warning, :published_at, :deleted_at)',
				[
					'activity_id' => $post->activityId,
					'object_id' => $post->objectId,
					'object_author_id' => $post->objectAuthorId,
					'actor_id' => $post->actorId,
					'kind' => $post->kind->value,
					'content' => $post->content,
					'content_warning' => $post->contentWarning,
					'published_at' => Time::format($post->publishedAt),
					'deleted_at' => $post->deletedAt === null ? null : Time::format($post->deletedAt),
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The remote post could not be stored.', 0, $exception);
		}
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
		?DateTimeImmutable $before = null,
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

		if ($before !== null) {
			$sql .= ' AND published_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY published_at DESC, activity_id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): RemotePost => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
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
		);
	}
}
