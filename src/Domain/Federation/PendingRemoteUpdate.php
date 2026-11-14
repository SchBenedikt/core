<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An edit that arrived before the post it belongs to.
 *
 * A peer may deliver `Update` before its `Create` (or the deliveries may
 * arrive out of order). The content is held for a bounded time and applied
 * when the post is stored, so the first version a follower sees is not
 * silently the superseded one. The row is keyed by object id and actor: only
 * the author that owns the object can fill it in later.
 */
final readonly class PendingRemoteUpdate
{
	/** Remote content is peer-controlled; keep one entry small and bounded. */
	public const MAX_CONTENT_LENGTH = 10_000;

	public function __construct(
		public string $objectId,
		public string $actorId,
		public string $activityId,
		public string $content,
		public ?string $contentWarning,
		public DateTimeImmutable $updatedAt,
		public DateTimeImmutable $receivedAt,
	) {
		if ($objectId === '' || $actorId === '' || $activityId === '') {
			throw new InvalidArgumentException('A pending update needs an object, an actor and an activity.');
		}

		if (mb_strlen($objectId) > RemotePost::MAX_ID_LENGTH || mb_strlen($activityId) > RemotePost::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('A pending update id is too long.');
		}

		if (mb_strlen($actorId) > RemotePost::MAX_ACTOR_LENGTH) {
			throw new InvalidArgumentException('A pending update actor is too long.');
		}

		if (trim($content) === '' || mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
			throw new InvalidArgumentException('A pending update needs bounded content.');
		}

		if ($contentWarning !== null && mb_strlen($contentWarning) > RemotePost::MAX_WARNING_LENGTH) {
			throw new InvalidArgumentException('A pending update warning is too long.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'object_id' => $this->objectId,
			'actor_id' => $this->actorId,
			'activity_id' => $this->activityId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'updated_at' => Time::format($this->updatedAt),
			'received_at' => Time::format($this->receivedAt),
		];
	}
}
