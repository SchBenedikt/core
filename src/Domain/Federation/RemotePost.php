<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A post or boost received from another instance.
 *
 * `activityId` is the idempotence key for a Create or Announce: the row is
 * stored at most once. A Delete is idempotent through the activity log and
 * the target row's soft-delete flag. `objectId` is the Note/object being shown
 * (or, for a boost, the object being boosted), while `actorId` is the remote
 * actor that signed the activity. A boost therefore has no content of its
 * own; its content is a snapshot of the target when the peer embedded it.
 */
final readonly class RemotePost
{
	public const MAX_ID_LENGTH = 512;

	public const MAX_ACTOR_LENGTH = 255;

	public const MAX_WARNING_LENGTH = 255;

	public function __construct(
		public string $activityId,
		public string $objectId,
		public string $actorId,
		public RemotePostKind $kind,
		public string $content,
		public ?string $contentWarning,
		public DateTimeImmutable $publishedAt,
		public ?string $objectAuthorId = null,
		public ?DateTimeImmutable $deletedAt = null,
		public ?DateTimeImmutable $updatedAt = null,
		public RemoteObjectType $objectType = RemoteObjectType::Note,
		public ?RemoteObjectMetadata $metadata = null,
		public ?string $quotedObjectId = null,
		public ?string $inReplyToId = null,
		/** The audited audience the origin addressed; null means unknown. */
		public ?Visibility $audience = null,
	) {
		if ($activityId === '' || $objectId === '' || $actorId === '') {
			throw new InvalidArgumentException('A remote post needs an activity, an object and an actor.');
		}

		if (mb_strlen($activityId) > self::MAX_ID_LENGTH || mb_strlen($objectId) > self::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('A remote activity or object id is too long.');
		}

		if (mb_strlen($actorId) > self::MAX_ACTOR_LENGTH) {
			throw new InvalidArgumentException('A remote actor id is too long.');
		}

		if ($contentWarning !== null && mb_strlen($contentWarning) > self::MAX_WARNING_LENGTH) {
			throw new InvalidArgumentException('A remote content warning is too long.');
		}
	}

	public function isBoost(): bool
	{
		return $this->kind === RemotePostKind::Boost;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'activity_id' => $this->activityId,
			'object_id' => $this->objectId,
			'object_author_id' => $this->objectAuthorId,
			'actor_id' => $this->actorId,
			'kind' => $this->kind->value,
			'object_type' => $this->objectType->value,
			'metadata' => $this->metadata?->toArray(),
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'published_at' => Time::format($this->publishedAt),
			'quoted_object_id' => $this->quotedObjectId,
			'in_reply_to_id' => $this->inReplyToId,
			'audience' => $this->audience?->value,
			'updated_at' => $this->updatedAt === null ? null : Time::format($this->updatedAt),
			'deleted_at' => $this->deletedAt === null ? null : Time::format($this->deletedAt),
		];
	}
}
