<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Moderation;

use DateTimeImmutable;

/**
 * Held-post storage for tests and the in-memory assembly.
 *
 * The claim transition is atomic in a single-process array exactly like the
 * conditional SQL update is in the database.
 */
final class InMemoryHeldPostRepository implements HeldPostRepository
{
	/**
	 * @var array<string, HeldPost>
	 */
	private array $posts = [];

	public function insert(HeldPost $post): void
	{
		$this->posts[$post->id] = $post;
	}

	public function find(string $id): ?HeldPost
	{
		return $this->posts[$id] ?? null;
	}

	public function queue(int $limit, ?DateTimeImmutable $before = null): array
	{
		$posts = array_values(array_filter(
			$this->posts,
			static fn (HeldPost $post): bool => $post->status === HeldPostStatus::Pending
				&& ($before === null || $post->createdAt < $before),
		));

		usort($posts, static function (HeldPost $a, HeldPost $b): int {
			$byTime = $a->createdAt <=> $b->createdAt;

			return $byTime !== 0 ? $byTime : strcmp($a->id, $b->id);
		});

		return array_slice($posts, 0, max(1, $limit));
	}

	public function claim(string $id, string $moderatorId, DateTimeImmutable $at): bool
	{
		$post = $this->posts[$id] ?? null;
		if ($post === null || $post->status !== HeldPostStatus::Pending) {
			return false;
		}

		$this->posts[$id] = $this->transition($post, HeldPostStatus::Reviewing, $moderatorId, $at);

		return true;
	}

	public function approve(
		string $id,
		string $postId,
		string $moderatorId,
		?string $note,
		DateTimeImmutable $at,
	): bool {
		$post = $this->posts[$id] ?? null;
		if ($post === null
			|| $post->status !== HeldPostStatus::Reviewing
			|| $post->reviewedBy !== $moderatorId
		) {
			return false;
		}

		$this->posts[$id] = new HeldPost(
			id: $post->id,
			authorId: $post->authorId,
			draft: $post->draft,
			reason: $post->reason,
			status: HeldPostStatus::Approved,
			postId: $postId,
			createdAt: $post->createdAt,
			reviewedBy: $moderatorId,
			reviewedAt: $at,
			reviewNote: $note,
		);

		return true;
	}

	public function reject(
		string $id,
		string $moderatorId,
		string $note,
		DateTimeImmutable $at,
	): bool {
		$post = $this->posts[$id] ?? null;
		if ($post === null
			|| $post->status !== HeldPostStatus::Reviewing
			|| $post->reviewedBy !== $moderatorId
		) {
			return false;
		}

		$this->posts[$id] = new HeldPost(
			id: $post->id,
			authorId: $post->authorId,
			draft: $post->draft,
			reason: $post->reason,
			status: HeldPostStatus::Rejected,
			postId: null,
			createdAt: $post->createdAt,
			reviewedBy: $moderatorId,
			reviewedAt: $at,
			reviewNote: $note,
		);

		return true;
	}

	public function release(string $id): void
	{
		$post = $this->posts[$id] ?? null;
		if ($post === null || $post->status !== HeldPostStatus::Reviewing) {
			return;
		}

		$this->posts[$id] = new HeldPost(
			id: $post->id,
			authorId: $post->authorId,
			draft: $post->draft,
			reason: $post->reason,
			status: HeldPostStatus::Pending,
			postId: null,
			createdAt: $post->createdAt,
		);
	}

	private function transition(
		HeldPost $post,
		HeldPostStatus $status,
		string $moderatorId,
		DateTimeImmutable $at,
	): HeldPost {
		return new HeldPost(
			id: $post->id,
			authorId: $post->authorId,
			draft: $post->draft,
			reason: $post->reason,
			status: $status,
			postId: $post->postId,
			createdAt: $post->createdAt,
			reviewedBy: $moderatorId,
			reviewedAt: $at,
		);
	}
}
