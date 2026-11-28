<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Scheduling;

use DateTimeImmutable;

use function array_filter;
use function array_slice;
use function array_values;
use function usort;

/**
 * In-memory scheduled posts, with the same claim semantics as the PDO store.
 *
 * The claim is a single guarded write: the condition is re-checked immediately
 * before the state changes, so it is the analogue of the SQL compare-and-set.
 */
final class InMemoryScheduledPostRepository implements ScheduledPostRepository
{
	/**
	 * @var array<string, ScheduledPost>
	 */
	private array $rows = [];

	public function insert(ScheduledPost $post): void
	{
		if (isset($this->rows[$post->id])) {
			throw new \AlohaSocial\Core\Platform\Db\DatabaseException('The scheduled post id is taken.');
		}

		foreach ($this->rows as $row) {
			if ($row->postId === $post->postId) {
				throw new \AlohaSocial\Core\Platform\Db\DatabaseException('The reserved post id is taken.');
			}
		}

		$this->rows[$post->id] = $post;
	}

	public function find(string $id): ?ScheduledPost
	{
		return $this->rows[$id] ?? null;
	}

	public function forAuthor(
		string $authorId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		$rows = array_values(array_filter(
			$this->rows,
			static fn (ScheduledPost $row): bool => $row->authorId === $authorId,
		));

		usort($rows, static function (ScheduledPost $left, ScheduledPost $right): int {
			$byTime = $right->createdAt <=> $left->createdAt;

			return $byTime !== 0 ? $byTime : $right->id <=> $left->id;
		});

		if ($before !== null) {
			$rows = array_values(array_filter(
				$rows,
				static fn (ScheduledPost $row): bool => $beforeId === null
					? $row->createdAt < $before
					: ($row->createdAt < $before
						|| ($row->createdAt == $before && $row->id < $beforeId)),
			));
		}

		return array_slice($rows, 0, max(1, $limit));
	}

	public function countCreatedSince(string $authorId, DateTimeImmutable $since): int
	{
		$count = 0;
		foreach ($this->rows as $row) {
			if ($row->authorId === $authorId && $row->createdAt >= $since) {
				$count++;
			}
		}

		return $count;
	}

	public function dueIds(DateTimeImmutable $now, int $limit): array
	{
		$due = [];
		foreach ($this->rows as $row) {
			$claimed = $row->state === ScheduledPostState::Publishing
				&& ($row->leaseUntil === null || $row->leaseUntil <= $now);
			if (!$row->isPending()
				|| $row->scheduledAt > $now
				|| ($row->state === ScheduledPostState::Publishing && !$claimed)
			) {
				continue;
			}

			$due[] = $row;
		}

		usort($due, static fn (ScheduledPost $left, ScheduledPost $right): int => $left->scheduledAt <=> $right->scheduledAt
			?: $left->id <=> $right->id);

		return array_slice(array_map(static fn (ScheduledPost $row): string => $row->id, $due), 0, max(1, $limit));
	}

	public function claim(string $id, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool
	{
		$row = $this->rows[$id] ?? null;
		if ($row === null || $row->scheduledAt > $now) {
			return false;
		}

		$claimable = $row->state === ScheduledPostState::Scheduled
			|| ($row->state === ScheduledPostState::Publishing
				&& ($row->leaseUntil === null || $row->leaseUntil <= $now));
		if (!$claimable) {
			return false;
		}

		$this->rows[$id] = $row->withState(ScheduledPostState::Publishing, leaseUntil: $leaseUntil);

		return true;
	}

	public function release(string $id): void
	{
		$row = $this->rows[$id] ?? null;
		if ($row !== null && $row->state === ScheduledPostState::Publishing) {
			$this->rows[$id] = $row->withState(ScheduledPostState::Scheduled);
		}
	}

	public function markPublished(string $id, DateTimeImmutable $publishedAt): void
	{
		$row = $this->rows[$id] ?? null;
		if ($row !== null) {
			$this->rows[$id] = $row->withState(ScheduledPostState::Published, publishedAt: $publishedAt);
		}
	}

	public function markFailed(string $id, string $reason, DateTimeImmutable $failedAt): void
	{
		$row = $this->rows[$id] ?? null;
		if ($row !== null) {
			$this->rows[$id] = $row->withState(
				ScheduledPostState::Failed,
				failureReason: $reason === '' ? null : $reason,
				publishedAt: $failedAt,
			);
		}
	}

	public function reschedule(string $id, DateTimeImmutable $scheduledAt): bool
	{
		$row = $this->rows[$id] ?? null;
		if ($row === null || !$row->isPending() || $row->state === ScheduledPostState::Publishing) {
			return false;
		}

		$this->rows[$id] = new ScheduledPost(
			id: $row->id,
			authorId: $row->authorId,
			postId: $row->postId,
			content: $row->content,
			visibility: $row->visibility,
			scheduledAt: $scheduledAt,
			createdAt: $row->createdAt,
			state: $row->state,
			contentWarning: $row->contentWarning,
			attachmentIds: $row->attachmentIds,
			recipientIds: $row->recipientIds,
			inReplyToId: $row->inReplyToId,
			language: $row->language,
			poll: $row->poll,
		);

		return true;
	}

	public function cancel(string $id): bool
	{
		$row = $this->rows[$id] ?? null;
		if ($row === null || !$row->isPending() || $row->state === ScheduledPostState::Publishing) {
			return false;
		}

		$this->rows[$id] = $row->withState(ScheduledPostState::Cancelled);

		return true;
	}
}
