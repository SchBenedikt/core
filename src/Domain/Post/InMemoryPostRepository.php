<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

use function array_fill;
use function array_fill_keys;
use function array_filter;
use function array_intersect;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function mb_stripos;
use function strcmp;
use function trim;
use function usort;

/**
 * Post storage in memory, for tests, the demo and tools.
 *
 * It applies exactly the ordering and visibility rules of the SQL
 * implementation, so behaviour cannot differ between the two unnoticed.
 */
final class InMemoryPostRepository implements PostRepository, PostSearchRepository, PostCount, PollVoteCounter
{
	/**
	 * @var array<string, Post>
	 */
	private array $posts = [];

	public function insert(Post $post): void
	{
		if (isset($this->posts[$post->id])) {
			throw new DatabaseException('The post id already exists.');
		}

		$this->posts[$post->id] = $post;
	}

	public function update(Post $post): void
	{
		$stored = $this->posts[$post->id] ?? null;
		if ($stored === null || $stored->isDeleted()) {
			return;
		}

		$this->posts[$post->id] = $post;
	}

	public function find(string $id): ?Post
	{
		return $this->posts[$id] ?? null;
	}

	public function softDelete(string $id, DateTimeImmutable $deletedAt): void
	{
		$post = $this->posts[$id] ?? null;
		if ($post === null || $post->isDeleted()) {
			return;
		}

		$this->posts[$id] = new Post(
			$post->id,
			$post->authorId,
			$post->content,
			$post->visibility,
			$post->publishedAt,
			$post->contentWarning,
			$deletedAt,
			$post->attachmentIds,
			$post->poll,
			$post->recipientIds,
			$post->inReplyToId,
			editedAt: $post->editedAt,
			revision: $post->revision,
			quotedObjectId: $post->quotedObjectId,
			quoteState: $post->quoteState,
			quoteAuthorizationId: $post->quoteAuthorizationId,
			quoteRequestId: $post->quoteRequestId,
			archivedAt: $post->archivedAt,
		);
	}

	public function archive(string $id, DateTimeImmutable $archivedAt): void
	{
		$post = $this->posts[$id] ?? null;
		if ($post === null || $post->isDeleted() || $post->isArchived()) {
			return;
		}

		$this->posts[$id] = $post->withArchivedAt($archivedAt);
	}

	public function unarchive(string $id): void
	{
		$post = $this->posts[$id] ?? null;
		if ($post !== null) {
			$this->posts[$id] = $post->withArchivedAt(null);
		}
	}

	public function archivedTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& $post->isArchived()
					&& $post->authorId === $userId,
			)),
			$limit,
			$before,
			$beforeId,
		);
	}

	public function archivedCount(string $userId): int
	{
		return count(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted()
				&& $post->isArchived()
				&& $post->authorId === $userId,
		));
	}

	public function updateQuoteState(string $postId, QuoteState $state, ?string $authorizationId = null): void
	{
		$post = $this->posts[$postId] ?? null;
		if ($post === null || $post->isDeleted() || $post->quotedObjectId === null || $post->quoteState === null) {
			return;
		}

		if ($post->quoteState->isFinal()) {
			return;
		}

		$this->posts[$postId] = new Post(
			$post->id,
			$post->authorId,
			$post->content,
			$post->visibility,
			$post->publishedAt,
			$post->contentWarning,
			$post->deletedAt,
			$post->attachmentIds,
			$post->poll,
			$post->recipientIds,
			$post->inReplyToId,
			editedAt: $post->editedAt,
			revision: $post->revision,
			quotedObjectId: $post->quotedObjectId,
			quoteState: $state,
			quoteAuthorizationId: $authorizationId,
			quoteRequestId: $post->quoteRequestId,
			archivedAt: $post->archivedAt,
		);
	}

	public function publicTimeline(
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		// Unlisted posts are readable by link but never enumerated; only public
		// rows belong in the public timeline.
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& !$post->isArchived()
					&& $post->visibility->mayListPublicly(),
			)),
			$limit,
			$before,
			$beforeId,
			$after,
			$afterId,
		);
	}

	public function hashtagTimeline(
		string $hashtag,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& !$post->isArchived()
					&& $post->visibility->mayListPublicly()
					&& in_array($hashtag, Hashtags::extract($post->content), true),
			)),
			$limit,
			$before,
			$beforeId,
		);
	}

	public function followedHashtagTimeline(
		array $hashtags,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array {
		if ($hashtags === []) {
			return [];
		}

		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& !$post->isArchived()
					&& $post->visibility->mayListPublicly()
					&& array_intersect($hashtags, Hashtags::extract($post->content)) !== [],
			)),
			$limit,
			$before,
			$beforeId,
		);
	}

	public function authorTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
		bool $includeFollowers = false,
	): array {
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& !$post->isArchived()
					&& $post->authorId === $userId
					&& ($post->visibility->isPubliclyVisible()
						|| ($includeFollowers && $post->visibility === Visibility::Followers)),
			)),
			$limit,
			$before,
			$beforeId,
			$after,
			$afterId,
		);
	}

	public function authorPosts(string $userId): array
	{
		$posts = array_values(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted()
				&& !$post->isArchived()
				&& $post->authorId === $userId
				&& $post->visibility->mayListPublicly(),
		));

		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $posts;
	}

	public function authorPostsPage(string $userId, int $limit, int $offset): array
	{
		return array_slice($this->authorPosts($userId), max(0, $offset), max(0, $limit));
	}

	public function authorPostCount(string $userId): int
	{
		return count($this->authorPosts($userId));
	}

	public function countLive(): int
	{
		return count(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted(),
		));
	}

	public function timelineForAuthors(array $authorIds, int $limit, DateTimeImmutable|TimelineCursor|null $before = null): array
	{
		$authors = array_fill_keys(array_values($authorIds), true);
		if ($authors === []) {
			return [];
		}

		$posts = array_values(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted()
				&& !$post->isArchived()
				&& $post->visibility !== Visibility::Direct
				&& isset($authors[$post->authorId])
				&& ($before === null || ($before instanceof TimelineCursor
					? ($post->publishedAt < $before->publishedAt
						|| ($post->publishedAt == $before->publishedAt && strcmp($post->id, $before->id) < 0))
					: $post->publishedAt < $before)),
		));

		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($posts, 0, max(1, $limit));
	}

	public function repliesTo(string $postId, int $limit, ?DateTimeImmutable $before = null): array
	{
		$replies = array_values(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted()
				&& $post->inReplyToId === $postId
				&& ($before === null || $post->publishedAt < $before),
		));

		usort($replies, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($replies, 0, max(1, $limit));
	}

	public function replyCount(string $postId): int
	{
		$count = 0;
		foreach ($this->posts as $post) {
			if (!$post->isDeleted() && $post->inReplyToId === $postId) {
				$count++;
			}
		}

		return $count;
	}

	public function replyCountsForPosts(array $postIds): array
	{
		$counts = array_fill_keys(array_values($postIds), 0);
		foreach ($this->posts as $post) {
			if ($post->isDeleted() || $post->inReplyToId === null || !isset($counts[$post->inReplyToId])) {
				continue;
			}

			$counts[$post->inReplyToId]++;
		}

		return $counts;
	}

	public function ownTimeline(string $userId, int $limit, DateTimeImmutable|TimelineCursor|null $before = null): array
	{
		return $this->slice(
			array_values(array_filter(
				$this->posts,
				static fn (Post $post): bool => !$post->isDeleted()
					&& !$post->isArchived()
					&& ($post->authorId === $userId
						|| ($post->visibility === Visibility::Direct && in_array($userId, $post->recipientIds, true))),
			)),
			$limit,
			$before instanceof TimelineCursor ? $before->publishedAt : $before,
			$before instanceof TimelineCursor ? $before->id : null,
		);
	}

	public function incrementPollVote(PollVote $vote): void
	{
		$post = $this->posts[$vote->postId] ?? null;
		if ($post === null || $post->poll === null) {
			throw new DatabaseException('The poll no longer exists.');
		}

		$poll = $post->poll;
		$counts = array_fill(0, count($poll->options), 0);
		foreach ($vote->positions as $position) {
			if (!isset($poll->options[$position])) {
				throw new DatabaseException('The poll option no longer exists.');
			}

			$counts[$position]++;
		}

		$options = [];
		foreach ($poll->options as $position => $option) {
			$options[] = new PollOption($option->title, $option->votesCount + $counts[$position]);
		}

		$this->posts[$post->id] = new Post(
			id: $post->id,
			authorId: $post->authorId,
			content: $post->content,
			visibility: $post->visibility,
			publishedAt: $post->publishedAt,
			contentWarning: $post->contentWarning,
			deletedAt: $post->deletedAt,
			attachmentIds: $post->attachmentIds,
			poll: new Poll(
				options: $options,
				multiple: $poll->multiple,
				hideTotals: $poll->hideTotals,
				expiresAt: $poll->expiresAt,
				votesCount: $poll->votesCount + count($vote->positions),
				votersCount: $poll->votersCount + 1,
			),
			recipientIds: $post->recipientIds,
			inReplyToId: $post->inReplyToId,
			editedAt: $post->editedAt,
			revision: $post->revision,
			quotedObjectId: $post->quotedObjectId,
			quoteState: $post->quoteState,
			quoteAuthorizationId: $post->quoteAuthorizationId,
			quoteRequestId: $post->quoteRequestId,
			archivedAt: $post->archivedAt,
		);
	}

	public function attachmentIdsFor(array $postIds): array
	{
		$result = [];
		foreach ($postIds as $postId) {
			if (isset($this->posts[$postId])) {
				$result[$postId] = $this->posts[$postId]->attachmentIds;
			}
		}

		return $result;
	}

	/**
	 * @param list<string>|null $authorIds
	 *
	 * @return list<Post>
	 */
	public function search(
		string $query,
		int $limit,
		int $offset,
		?array $authorIds = null,
	): array {
		$query = trim($query);
		if ($query === '' || $limit < 1 || $authorIds === []) {
			return [];
		}

		$allowed = $authorIds === null ? null : array_fill_keys($authorIds, true);
		$matches = array_values(array_filter(
			$this->posts,
			static fn (Post $post): bool => !$post->isDeleted()
				&& !$post->isArchived()
				&& $post->visibility->mayListPublicly()
				&& ($allowed === null || isset($allowed[$post->authorId]))
				&& mb_stripos($post->content, $query) !== false,
		));

		usort($matches, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return array_slice($matches, max(0, $offset), max(1, $limit));
	}

	public function postsForAttachment(string $attachmentId): array
	{
		$posts = array_values(array_filter(
			$this->posts,
			static fn (Post $post): bool => in_array($attachmentId, $post->attachmentIds, true),
		));

		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		return $posts;
	}

	public function isAttachmentLinked(string $attachmentId): bool
	{
		foreach ($this->posts as $post) {
			if (in_array($attachmentId, $post->attachmentIds, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<Post>
	 */
	private function slice(
		array $posts,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array {
		usort($posts, static function (Post $a, Post $b): int {
			$byTime = $b->publishedAt <=> $a->publishedAt;

			return $byTime !== 0 ? $byTime : strcmp($b->id, $a->id);
		});

		$result = [];
		foreach ($posts as $post) {
			if ($before !== null) {
				if ($post->publishedAt > $before) {
					continue;
				}

				if ($post->publishedAt == $before && $beforeId !== null && strcmp($post->id, $beforeId) >= 0) {
					continue;
				}

				if ($post->publishedAt == $before && $beforeId === null) {
					continue;
				}
			}

			if ($after !== null) {
				if ($post->publishedAt < $after) {
					continue;
				}

				if ($post->publishedAt == $after && $afterId !== null && strcmp($post->id, $afterId) <= 0) {
					continue;
				}

				if ($post->publishedAt == $after && $afterId === null) {
					continue;
				}
			}

			$result[] = $post;
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}
}
