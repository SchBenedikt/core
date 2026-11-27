<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use DateTimeImmutable;
use AlohaSocial\Core\Domain\Post\TimelineCursor;

/**
 * Storage of posts and boosts received from remote actors.
 *
 * The activity id is the primary key, so a duplicate delivery cannot create a
 * second timeline row. Deletes are soft: a tombstone keeps the activity id
 * claimed and lets the home timeline filter the object without losing the
 * ability to answer a later duplicate.
 */
interface RemotePostRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the activity id already exists
	 */
	public function insert(RemotePost $post): void;

	/**
	 * Looks up a row including a soft-deleted one, for duplicate detection.
	 */
	public function findByActivityId(string $activityId): ?RemotePost;

	/**
	 * Rows by activity id in one bounded read, soft-deleted included, keyed
	 * by activity id. Unknown ids are simply absent from the result.
	 *
	 * @param list<string> $activityIds
	 *
	 * @return array<string, RemotePost>
	 */
	public function findByActivityIds(array $activityIds): array;

	/**
	 * The live post for an object id; boosts are deliberately excluded.
	 */
	public function findPostByObjectId(string $objectId): ?RemotePost;

	/**
	 * Applies an author's edit to the stored post for an object.
	 *
	 * Only a live `Post` row of that actor is changed; a boost snapshot of the
	 * same object is a different row and keeps what it announced. The caller
	 * has already checked ownership and ordering.
	 *
	 * @return bool whether a row was updated
	 */
	public function updatePostContent(
		string $objectId,
		string $actorId,
		string $content,
		?string $contentWarning,
		DateTimeImmutable $updatedAt,
	): bool;

	/**
	 * Soft-deletes one activity if the actor owns it.
	 *
	 * @return bool whether a live row was removed
	 */
	public function softDeleteByActivityId(
		string $activityId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool;

	/**
	 * Soft-deletes the live post for an object if the actor owns it.
	 *
	 * @return bool whether a live row was removed
	 */
	public function softDeletePostByObjectId(
		string $objectId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool;

	/**
	 * Live posts and boosts by the given actors, newest first.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return list<RemotePost>
	 */
	public function timelineForActors(
		array $actorIds,
		int $limit,
		DateTimeImmutable|TimelineCursor|null $before = null,
	): array;

	/**
	 * Replaces the imported-attachment links for one activity.
	 *
	 * Linking is deliberately separate from insert so a post stays available
	 * when one or all of its media imports fail. Passing an empty list removes
	 * existing links. The caller must keep the list within the post attachment
	 * limit.
	 *
	 * @param list<string> $attachmentIds
	 *
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException
	 */
	public function linkAttachments(string $activityId, array $attachmentIds): void;

	/**
	 * @param list<string> $activityIds
	 *
	 * @return array<string, list<string>> attachment ids keyed by activity id,
	 *                                       in stored order
	 */
	public function attachmentIdsFor(array $activityIds): array;

	/**
	 * The remote posts and boosts that still link this attachment, deleted
	 * rows included so callers can apply their own tombstone rule.
	 *
	 * @return list<RemotePost>
	 */
	public function postsForAttachment(string $attachmentId): array;

	/**
	 * Whether any remote post or boost still links this attachment.
	 *
	 * Cleanup uses this together with the local post link so a shared remote
	 * image is never deleted just because no local post references it.
	 */
	public function isAttachmentLinked(string $attachmentId): bool;
}
