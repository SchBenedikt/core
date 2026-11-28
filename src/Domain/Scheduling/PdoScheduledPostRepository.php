<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;
use PDOException;

use function array_map;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Scheduled posts in SQL.
 *
 * The claim is one conditional UPDATE: the row only changes when the same
 * condition the in-memory store checks still holds, so two workers cannot both
 * receive the row. The reserved post id is unique, which turns a retry after a
 * crash into a duplicate-key error instead of a second publication.
 */
final class PdoScheduledPostRepository implements ScheduledPostRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(ScheduledPost $post): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . ScheduledPostSchema::TABLE
				. ' (id, author_id, post_id, content, content_warning, visibility, language, attachment_ids,'
				. ' recipient_ids, in_reply_to_id, scheduled_at, created_at, state, lease_until,'
				. ' published_at, failure_reason, poll)'
				. ' VALUES (:id, :author_id, :post_id, :content, :content_warning, :visibility, :language,'
				. ' :attachment_ids, :recipient_ids, :in_reply_to_id, :scheduled_at, :created_at,'
				. ' :state, NULL, NULL, NULL, :poll)',
				[
					'id' => $post->id,
					'author_id' => $post->authorId,
					'post_id' => $post->postId,
					'content' => $post->content,
					'content_warning' => $post->contentWarning,
					'visibility' => $post->visibility->value,
					'language' => $post->language,
					'attachment_ids' => $this->encodeList($post->attachmentIds),
					'recipient_ids' => $this->encodeList($post->recipientIds),
					'in_reply_to_id' => $post->inReplyToId,
					'scheduled_at' => Time::format($post->scheduledAt),
					'created_at' => Time::format($post->createdAt),
					'state' => $post->state->value,
					'poll' => $post->poll === null ? null : $this->encodePoll($post->poll),
				],
			);
		} catch (PDOException $exception) {
			throw new \AlohaSocial\Core\Platform\Db\DatabaseException(
				'The scheduled post could not be stored.',
				0,
				$exception,
			);
		}
	}

	public function find(string $id): ?ScheduledPost
	{
		$row = $this->db->fetchOne(
			'SELECT * FROM ' . ScheduledPostSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function forAuthor(
		string $authorId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		$sql = 'SELECT * FROM ' . ScheduledPostSchema::TABLE . ' WHERE author_id = :author_id';
		$parameters = ['author_id' => $authorId];

		if ($before !== null) {
			if ($beforeId === null) {
				$sql .= ' AND created_at < :before';
			} else {
				$sql .= ' AND (created_at < :before OR (created_at = :before AND id < :before_id))';
				$parameters['before_id'] = $beforeId;
			}

			$parameters['before'] = Time::format($before);
		}

		$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);

		return array_map(
			fn (array $row): ScheduledPost => $this->hydrate($row),
			$this->db->fetchAll($sql, $parameters),
		);
	}

	public function countCreatedSince(string $authorId, DateTimeImmutable $since): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . ScheduledPostSchema::TABLE
			. ' WHERE author_id = :author_id AND created_at >= :since',
			['author_id' => $authorId, 'since' => Time::format($since)],
		);
	}

	public function dueIds(DateTimeImmutable $now, int $limit): array
	{
		$rows = $this->db->fetchAll(
			'SELECT id FROM ' . ScheduledPostSchema::TABLE
			. ' WHERE scheduled_at <= :now'
			. ' AND (state = :scheduled OR (state = :publishing'
			. ' AND (lease_until IS NULL OR lease_until <= :now)))'
			. ' ORDER BY scheduled_at ASC, id ASC LIMIT ' . max(1, $limit),
			[
				'now' => Time::format($now),
				'scheduled' => ScheduledPostState::Scheduled->value,
				'publishing' => ScheduledPostState::Publishing->value,
			],
		);

		return array_map(static fn (array $row): string => (string) $row['id'], $rows);
	}

	public function claim(string $id, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool
	{
		return $this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE
			. ' SET state = :publishing, lease_until = :lease_until'
			. ' WHERE id = :id AND scheduled_at <= :now'
			. ' AND (state = :scheduled OR (state = :publishing'
			. ' AND (lease_until IS NULL OR lease_until <= :now)))',
			[
				'publishing' => ScheduledPostState::Publishing->value,
				'lease_until' => Time::format($leaseUntil),
				'id' => $id,
				'now' => Time::format($now),
				'scheduled' => ScheduledPostState::Scheduled->value,
			],
		) > 0;
	}

	public function release(string $id): void
	{
		$this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE
			. ' SET state = :scheduled, lease_until = NULL'
			. ' WHERE id = :id AND state = :publishing',
			[
				'scheduled' => ScheduledPostState::Scheduled->value,
				'id' => $id,
				'publishing' => ScheduledPostState::Publishing->value,
			],
		);
	}

	public function markPublished(string $id, DateTimeImmutable $publishedAt): void
	{
		$this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE
			. ' SET state = :published, published_at = :published_at, lease_until = NULL'
			. ' WHERE id = :id',
			[
				'published' => ScheduledPostState::Published->value,
				'published_at' => Time::format($publishedAt),
				'id' => $id,
			],
		);
	}

	public function markFailed(string $id, string $reason, DateTimeImmutable $failedAt): void
	{
		$this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE
			. ' SET state = :failed, failure_reason = :reason, lease_until = NULL'
			. ' WHERE id = :id',
			[
				'failed' => ScheduledPostState::Failed->value,
				'reason' => $reason === '' ? null : $reason,
				'id' => $id,
			],
		);
	}

	public function reschedule(string $id, DateTimeImmutable $scheduledAt): bool
	{
		return $this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE . ' SET scheduled_at = :scheduled_at'
			. ' WHERE id = :id AND state = :scheduled',
			[
				'scheduled_at' => Time::format($scheduledAt),
				'id' => $id,
				'scheduled' => ScheduledPostState::Scheduled->value,
			],
		) > 0;
	}

	public function update(ScheduledPost $post): bool
	{
		return $this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE
			. ' SET content = :content, content_warning = :content_warning, visibility = :visibility,'
			. ' language = :language, attachment_ids = :attachment_ids, recipient_ids = :recipient_ids,'
			. ' in_reply_to_id = :in_reply_to_id, scheduled_at = :scheduled_at, poll = :poll'
			. ' WHERE id = :id AND state = :scheduled',
			[
				'content' => $post->content,
				'content_warning' => $post->contentWarning,
				'visibility' => $post->visibility->value,
				'language' => $post->language,
				'attachment_ids' => $this->encodeList($post->attachmentIds),
				'recipient_ids' => $this->encodeList($post->recipientIds),
				'in_reply_to_id' => $post->inReplyToId,
				'scheduled_at' => Time::format($post->scheduledAt),
				'poll' => $post->poll === null ? null : $this->encodePoll($post->poll),
				'id' => $post->id,
				'scheduled' => ScheduledPostState::Scheduled->value,
			],
		) > 0;
	}

	public function cancel(string $id): bool
	{
		return $this->db->execute(
			'UPDATE ' . ScheduledPostSchema::TABLE . ' SET state = :cancelled, lease_until = NULL'
			. ' WHERE id = :id AND state = :scheduled',
			[
				'cancelled' => ScheduledPostState::Cancelled->value,
				'id' => $id,
				'scheduled' => ScheduledPostState::Scheduled->value,
			],
		) > 0;
	}

	/**
	 * @param list<string> $values
	 */
	private function encodeList(array $values): string
	{
		try {
			return json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException) {
			return '[]';
		}
	}

	private function encodePoll(ScheduledPoll $poll): string
	{
		try {
			return json_encode($poll->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException) {
			return '{}';
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function decodePoll(?string $encoded): ?array
	{
		if ($encoded === null || $encoded === '') {
			return null;
		}

		try {
			$decoded = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @return list<string>
	 */
	private function decodeList(?string $encoded): array
	{
		if ($encoded === null || $encoded === '') {
			return [];
		}

		try {
			$decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}

		if (!is_array($decoded)) {
			return [];
		}

		$values = [];
		foreach ($decoded as $value) {
			if (is_string($value) && $value !== '') {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): ScheduledPost
	{
		return new ScheduledPost(
			id: (string) $row['id'],
			authorId: (string) $row['author_id'],
			postId: (string) $row['post_id'],
			content: (string) $row['content'],
			visibility: Visibility::fromString((string) $row['visibility']),
			scheduledAt: Time::parse((string) $row['scheduled_at']),
			createdAt: Time::parse((string) $row['created_at']),
			state: ScheduledPostState::fromString((string) $row['state']),
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			attachmentIds: $this->decodeList($row['attachment_ids'] ?? null),
			recipientIds: $this->decodeList($row['recipient_ids'] ?? null),
			inReplyToId: $row['in_reply_to_id'] === null ? null : (string) $row['in_reply_to_id'],
			publishedAt: $row['published_at'] === null ? null : Time::parse((string) $row['published_at']),
			failureReason: $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
			leaseUntil: $row['lease_until'] === null ? null : Time::parse((string) $row['lease_until']),
			language: ($row['language'] ?? null) === null ? null : (string) $row['language'],
			poll: ScheduledPoll::fromArray($this->decodePoll($row['poll'] ?? null)),
		);
	}
}
