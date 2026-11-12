<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use DateTimeImmutable;

/**
 * Storage of posts.
 *
 * Timelines are newest first, with the post id as the tie-breaker, so paging
 * is stable even when two posts share a second. `$before` is exclusive.
 */
interface PostRepository
{
	/**
	 * Inserts a new post.
	 *
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is taken
	 */
	public function insert(Post $post): void;

	public function find(string $id): ?Post;

	/**
	 * Marks a post as deleted. Deleting an unknown id is a no-op.
	 */
	public function softDelete(string $id, DateTimeImmutable $deletedAt): void;

	/**
	 * Everything the public timelines may show.
	 *
	 * @return list<Post>
	 */
	public function publicTimeline(
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array;

	/**
	 * Publicly visible posts of one author, with the same id-aware cursor as
	 * {@see publicTimeline()}.
	 *
	 * @return list<Post>
	 */
	public function authorTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
	): array;

	/**
	 * Every publicly visible post of one author, newest first, for the outbox.
	 *
	 * Only public and unlisted posts are returned: the outbox is a public
	 * endpoint, and listing a followers-only post there would leak it to
	 * anyone who can guess the actor URL.
	 *
	 * @return list<Post>
	 */
	public function authorPosts(string $userId): array;

	/**
	 * One page of the same public-author collection, newest first.
	 *
	 * @return list<Post>
	 */
	public function authorPostsPage(string $userId, int $limit, int $offset): array;

	/**
	 * The number of rows {@see authorPostsPage()} pages over.
	 */
	public function authorPostCount(string $userId): int;

	/**
	 * Every live post of one local user, including non-public ones.
	 *
	 * The combined home timeline uses this for the reader's own posts; the
	 * remote half comes from {@see \AlohaSocial\Core\Domain\Federation\RemotePostRepository}.
	 *
	 * @return list<Post>
	 */
	public function ownTimeline(string $userId, int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * Live posts of several local authors, newest first.
	 *
	 * Used by the home timeline for local accounts the reader follows. Visibility
	 * is not filtered: an accepted local follower may also see followers-only
	 * and unlisted posts.
	 *
	 * @param list<string> $authorIds
	 *
	 * @return list<Post>
	 */
	public function timelineForAuthors(array $authorIds, int $limit, ?DateTimeImmutable $before = null): array;


	/**
	 * Attachment ids for a set of posts, in their stored order.
	 *
	 * The home timeline's local entries carry a post id but not the full post;
	 * the presenter uses this one query instead of loading each post again.
	 *
	 * @param list<string> $postIds
	 *
	 * @return array<string, list<string>> keyed by post id
	 */
	public function attachmentIdsFor(array $postIds): array;

	/**
	 * Whether any post link still references the attachment.
	 *
	 * Soft-deleted posts count: their link row survives the tombstone, so
	 * deleting the metadata would leave a dangling reference. A host cleanup
	 * may remove such links after the post is gone.
	 */
	public function isAttachmentLinked(string $attachmentId): bool;
}
