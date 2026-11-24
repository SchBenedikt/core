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

	/**
	 * Replaces the editable fields of a live post.
	 *
	 * Content, warning, visibility, revision and `edited_at` are written and
	 * the attachment and hashtag links are rebuilt, so the indices agree with
	 * the new content. The row keeps its id, author, publication time and
	 * recipients. A deleted post is not revived and an unknown id is a no-op;
	 * the caller has already loaded and authorised the post.
	 */
	public function update(Post $post): void;

	public function find(string $id): ?Post;

	/**
	 * Applies the answer to an outgoing quote to the stored post.
	 *
	 * The quote target itself and the request id stay; only the state and, for
	 * an acceptance, the authorization id change. Rejected and revoked stay
	 * final, so a late Accept cannot revive a withdrawn grant.
	 */
	public function updateQuoteState(string $postId, QuoteState $state, ?string $authorizationId = null): void;

	/**
	 * Marks a post as deleted. Deleting an unknown id is a no-op.
	 */
	public function softDelete(string $id, DateTimeImmutable $deletedAt): void;

	/**
	 * Marks a live post archived without changing its publication or
	 * federation state. Unknown and deleted ids are a no-op.
	 */
	public function archive(string $id, DateTimeImmutable $archivedAt): void;

	/** Reverses {@see archive()}; unknown rows are a no-op. */
	public function unarchive(string $id): void;

	/**
	 * Archived live posts of one author, newest first. Owner-only by contract;
	 * the caller has already checked the account.
	 *
	 * @return list<Post>
	 */
	public function archivedTimeline(
		string $userId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
	): array;

	public function archivedCount(string $userId): int;

	/**
	 * Everything the public timelines may show.
	 *
	 * Archived posts are omitted from discovery even though their detail
	 * route stays addressable. Only `public` posts: unlisted is dereferenceable but must not be
	 * enumerated, so it never appears here.
	 *
	 * @return list<Post>
	 */
	public function publicTimeline(
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
		bool $hideReplies = false,
	): array;

	/**
	 * Public posts carrying exactly one hashtag, newest first.
	 *
	 * The tag is already normalized by the caller. Unlisted, followers-only
	 * and direct posts are never returned: a tag timeline is a discoverable
	 * public surface, and listing an unlisted post there would undo its
	 * omission from discovery.
	 *
	 * @return list<Post>
	 */
	public function hashtagTimeline(
		string $hashtag,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		bool $hideReplies = false,
	): array;

	/**
	 * Public posts carrying any of the supplied normalized tags, newest first,
	 * with the same id-aware cursor as the single-tag timeline. A post with
	 * two followed tags appears once. Unlisted, followers-only and direct
	 * posts are never returned: following a tag does not make an unlisted post
	 * discoverable.
	 *
	 * @param list<string> $hashtags
	 *
	 * @return list<Post>
	 */
	public function followedHashtagTimeline(
		array $hashtags,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		bool $hideReplies = false,
	): array;

	/**
	 * Live posts of one author, with the same id-aware cursor as
	 * {@see publicTimeline()}.
	 *
	 * Public and unlisted posts are always included. `$includeFollowers` adds
	 * followers-only posts for a caller that has already established the author
	 * or accepted-follower relationship; direct posts are never included.
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
		bool $includeFollowers = false,
	): array;

	/**
	 * Every `public` post of one author, newest first, for the outbox.
	 *
	 * The outbox is an enumerable public endpoint, so neither a followers-only
	 * post nor an unlisted one belongs in it: a follower-only row would leak
	 * and an unlisted row would defeat its omission from discovery. The note
	 * URL of an unlisted post stays fetchable.
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
	 * Every live post of one local user, including non-public ones, plus
	 * direct posts addressed to that user.
	 *
	 * The combined home timeline uses this for the reader's own posts and
	 * direct messages; the remote half comes from
	 * {@see \AlohaSocial\Core\Domain\Federation\RemotePostRepository}.
	 *
	 * @return list<Post>
	 */
	public function ownTimeline(string $userId, int $limit, DateTimeImmutable|TimelineCursor|null $before = null): array;

	/**
	 * Direct posts sent by or addressed to one local account, newest first.
	 * Only live, non-archived direct posts are returned.
	 *
	 * @return list<Post>
	 */
	public function directTimelineForUser(string $userId, int $limit, DateTimeImmutable|TimelineCursor|null $before = null): array;

	/**
	 * Live posts of several local authors, newest first.
	 *
	 * Used by the home timeline for local accounts the reader follows. Public,
	 * unlisted and followers-only posts are included; direct posts are excluded
	 * because their recipients are explicit and are not modelled here.
	 *
	 * @param list<string> $authorIds
	 *
	 * @return list<Post>
	 */
	public function timelineForAuthors(array $authorIds, int $limit, DateTimeImmutable|TimelineCursor|null $before = null, bool $hideReplies = false): array;

	/**
	 * Live direct replies to one post, newest first.
	 *
	 * Visibility is not filtered here: the caller has a viewer context and
	 * must apply the same check as the detail route before returning a row.
	 *
	 * @return list<Post>
	 */
	public function repliesTo(string $postId, int $limit, ?DateTimeImmutable $before = null): array;

	/**
	 * Number of live direct replies to one post.
	 */
	public function replyCount(string $postId): int;

	/**
	 * @param list<string> $postIds
	 *
	 * @return array<string, int> every requested id, with zero for no replies
	 */
	public function replyCountsForPosts(array $postIds): array;

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
	/**
	 * Every post linking an attachment, newest first.
	 *
	 * Deleted posts are included: their link rows survive the tombstone, and a
	 * reader has to be able to tell "no post publishes this" from "only a
	 * deleted post used to". The first attaching author is the only one who
	 * may link it, so the returned posts always share an author.
	 *
	 * @return list<Post>
	 */
	public function postsForAttachment(string $attachmentId): array;

	public function isAttachmentLinked(string $attachmentId): bool;
}
