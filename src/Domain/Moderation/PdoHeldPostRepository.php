<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * SQL adapter for the held-post queue.
 */
final class PdoHeldPostRepository implements HeldPostRepository
{
	private const COLUMNS = 'id, author_id, draft, reason, status, post_id, created_at,'
		. ' reviewed_by, reviewed_at, review_note';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(HeldPost $post): void
	{
		$this->db->execute(
			'INSERT INTO ' . HeldPostSchema::TABLE
			. ' (' . self::COLUMNS . ') VALUES (:id, :author_id, :draft, :reason, :status, :post_id,'
			. ' :created_at, :reviewed_by, :reviewed_at, :review_note)',
			[
				'id' => $post->id,
				'author_id' => $post->authorId,
				'draft' => json_encode($post->draft, JSON_THROW_ON_ERROR),
				'reason' => $post->reason,
				'status' => $post->status->value,
				'post_id' => $post->postId,
				'created_at' => Time::format($post->createdAt),
				'reviewed_by' => $post->reviewedBy,
				'reviewed_at' => $post->reviewedAt === null ? null : Time::format($post->reviewedAt),
				'review_note' => $post->reviewNote,
			],
		);
	}

	public function find(string $id): ?HeldPost
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . HeldPostSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function queue(int $limit, ?DateTimeImmutable $before = null): array
	{
		$sql = 'SELECT ' . self::COLUMNS . ' FROM ' . HeldPostSchema::TABLE . " WHERE status = 'pending'";
		$parameters = [];
		if ($before !== null) {
			$sql .= ' AND created_at < :before';
			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY created_at ASC, id ASC LIMIT ' . max(1, $limit);

		return array_map(fn (array $row): HeldPost => $this->hydrate($row), $this->db->fetchAll($sql, $parameters));
	}

	public function claim(string $id, string $moderatorId, DateTimeImmutable $at): bool
	{
		return $this->db->execute(
			'UPDATE ' . HeldPostSchema::TABLE
			. " SET status = 'reviewing', reviewed_by = :moderator_id, reviewed_at = :at"
			. " WHERE id = :id AND status = 'pending'",
			['id' => $id, 'moderator_id' => $moderatorId, 'at' => Time::format($at)],
		) > 0;
	}

	public function approve(
		string $id,
		string $postId,
		string $moderatorId,
		?string $note,
		DateTimeImmutable $at,
	): bool {
		return $this->db->execute(
			'UPDATE ' . HeldPostSchema::TABLE
			. " SET status = 'approved', post_id = :post_id, review_note = :note, reviewed_at = :at"
			. " WHERE id = :id AND status = 'reviewing' AND reviewed_by = :moderator_id",
			[
				'id' => $id,
				'post_id' => $postId,
				'note' => $note,
				'at' => Time::format($at),
				'moderator_id' => $moderatorId,
			],
		) > 0;
	}

	public function reject(
		string $id,
		string $moderatorId,
		string $note,
		DateTimeImmutable $at,
	): bool {
		return $this->db->execute(
			'UPDATE ' . HeldPostSchema::TABLE
			. " SET status = 'rejected', review_note = :note, reviewed_at = :at"
			. " WHERE id = :id AND status = 'reviewing' AND reviewed_by = :moderator_id",
			['id' => $id, 'note' => $note, 'at' => Time::format($at), 'moderator_id' => $moderatorId],
		) > 0;
	}

	public function release(string $id): void
	{
		$this->db->execute(
			'UPDATE ' . HeldPostSchema::TABLE
			. " SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL"
			. " WHERE id = :id AND status = 'reviewing'",
			['id' => $id],
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): HeldPost
	{
		try {
			$draft = json_decode((string) $row['draft'], true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$draft = [];
		}

		return new HeldPost(
			id: (string) $row['id'],
			authorId: (string) $row['author_id'],
			draft: is_array($draft) ? $draft : [],
			reason: (string) $row['reason'],
			status: HeldPostStatus::from((string) $row['status']),
			postId: $row['post_id'] === null ? null : (string) $row['post_id'],
			createdAt: Time::parse((string) $row['created_at']),
			reviewedBy: $row['reviewed_by'] === null ? null : (string) $row['reviewed_by'],
			reviewedAt: $row['reviewed_at'] === null ? null : Time::parse((string) $row['reviewed_at']),
			reviewNote: $row['review_note'] === null ? null : (string) $row['review_note'],
		);
	}
}
