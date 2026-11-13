<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

/**
 * A remote-post repository that fails only at the attachment-link step.
 */
final class FailingRemotePostLinkRepository implements RemotePostRepository
{
	public function __construct(
		private readonly RemotePostRepository $inner,
	) {
	}

	public function insert(RemotePost $post): void
	{
		$this->inner->insert($post);
	}

	public function findByActivityId(string $activityId): ?RemotePost
	{
		return $this->inner->findByActivityId($activityId);
	}

	public function findPostByObjectId(string $objectId): ?RemotePost
	{
		return $this->inner->findPostByObjectId($objectId);
	}

	public function softDeleteByActivityId(
		string $activityId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool {
		return $this->inner->softDeleteByActivityId($activityId, $actorId, $deletedAt);
	}

	public function softDeletePostByObjectId(
		string $objectId,
		string $actorId,
		DateTimeImmutable $deletedAt,
	): bool {
		return $this->inner->softDeletePostByObjectId($objectId, $actorId, $deletedAt);
	}

	public function timelineForActors(
		array $actorIds,
		int $limit,
		?DateTimeImmutable $before = null,
	): array {
		return $this->inner->timelineForActors($actorIds, $limit, $before);
	}

	public function linkAttachments(string $activityId, array $attachmentIds): void
	{
		throw new DatabaseException('The test link failure.');
	}

	public function attachmentIdsFor(array $activityIds): array
	{
		return $this->inner->attachmentIdsFor($activityIds);
	}

	public function isAttachmentLinked(string $attachmentId): bool
	{
		return $this->inner->isAttachmentLinked($attachmentId);
	}
}
