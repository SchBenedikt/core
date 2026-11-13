<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;

use function array_map;
use function max;
use function min;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * The neutral local-account read model behind `/api/v1`.
 *
 * It is deliberately smaller than the Mastodon projection: the shared UI and
 * a first-party host need a stable account, relationship and notification
 * shape, not a compatibility payload. The local relationship service remains
 * the single source of truth for follows; this service only shapes its answer
 * and turns notification ids into pages.
 *
 * A host without a local actor directory cannot serve profiles or follows.
 * The notification methods still work because a notification belongs to a
 * user id, not to a profile lookup.
 */
final class LocalAccountService
{
	public const DEFAULT_NOTIFICATION_LIMIT = 20;

	public const MAX_NOTIFICATION_LIMIT = 40;

	public function __construct(
		private readonly ?LocalRelationshipService $relationships,
		private readonly NotificationService $notifications,
		private readonly ActivityFactory $activities,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @throws UnknownLocalActorException when the id is not a local account
	 * @throws ConfigurationException     when no local directory is configured
	 *
	 * @return array<string, mixed>
	 */
	public function profile(string $accountId, ?string $viewerId): array
	{
		$relationships = $this->requireRelationships();
		$accountId = trim($accountId);
		if ($accountId === '' || !$relationships->hasLocalActor($accountId)) {
			throw new UnknownLocalActorException($accountId);
		}

		return [
			'id' => $accountId,
			'handle' => $accountId,
			'display_name' => $accountId,
			'url' => rtrim($this->activities->baseUrl(), '/') . '/@' . rawurlencode($accountId),
			'followers_count' => $relationships->followersCount($accountId),
			'following_count' => $relationships->followingCount($accountId),
			'relationship' => $viewerId !== null && $relationships->hasLocalActor($viewerId)
				? $this->relationship($viewerId, $accountId)
				: null,
		];
	}

	/**
	 * @throws UnknownLocalActorException
	 * @throws ValidationException
	 * @throws ConfigurationException
	 *
	 * @return array<string, mixed>
	 */
	public function follow(string $followerId, string $targetId): array
	{
		$relationships = $this->requireRelationships();
		$relationships->follow($followerId, $targetId);

		return $this->relationship($followerId, $targetId);
	}

	/**
	 * @throws UnknownLocalActorException
	 * @throws ConfigurationException
	 *
	 * @return array<string, mixed>
	 */
	public function unfollow(string $followerId, string $targetId): array
	{
		$relationships = $this->requireRelationships();
		$relationships->unfollow($followerId, $targetId);

		return $this->relationship($followerId, $targetId);
	}

	/**
	 * @throws UnknownLocalActorException
	 * @throws ConfigurationException
	 *
	 * @return array{following: bool, followed_by: bool, blocking: bool, muting: bool}
	 */
	public function relationship(string $viewerId, string $targetId): array
	{
		$relationship = $this->requireRelationships()->relationship($viewerId, $targetId);

		return [
			'following' => $relationship['following'],
			'followed_by' => $relationship['followed_by'],
			'blocking' => $relationship['blocking'],
			'muting' => $relationship['muting'],
		];
	}

	/**
	 * One page of unread notifications plus the current unread count.
	 *
	 * The cursor is a notification id owned by the caller, not a timestamp:
	 * two events can share a second and an id cursor keeps the page stable.
	 *
	 * @throws ValidationException when the cursor is unknown or foreign
	 *
	 * @return array{notifications: list<array<string, mixed>>, unread_count: int}
	 */
	public function notifications(string $userId, int $limit, ?string $before = null): array
	{
		$limit = max(1, min(self::MAX_NOTIFICATION_LIMIT, $limit));
		$beforeAt = null;
		$beforeId = null;

		if ($before !== null && trim($before) !== '') {
			$cursor = $this->notifications->find(trim($before));
			if ($cursor === null || $cursor->recipientId !== $userId) {
				throw new ValidationException([
					'before' => 'The notification cursor does not exist for this account.',
				]);
			}

			$beforeAt = $cursor->createdAt;
			$beforeId = $cursor->id;
		}

		$rows = $this->notifications->listFor($userId, $limit, $beforeAt, $beforeId);

		return [
			'notifications' => array_map(
				fn (Notification $notification): array => $this->notification($notification),
				$rows,
			),
			'unread_count' => $this->notifications->unreadCount($userId),
		];
	}

	/**
	 * Marks one notification read. A missing or foreign id answers false; an
	 * already-read row of the caller answers true, so a retry is idempotent.
	 */
	public function dismiss(string $userId, string $notificationId): bool
	{
		return $this->notifications->markRead($userId, $notificationId, $this->clock->now());
	}

	public function clear(string $userId): int
	{
		return $this->notifications->markAllRead($userId, $this->clock->now());
	}

	public function unreadCount(string $userId): int
	{
		return $this->notifications->unreadCount($userId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function notification(Notification $notification): array
	{
		return [
			'id' => $notification->id,
			'type' => $notification->type->value,
			'actor_id' => $notification->actorId,
			'post_id' => $notification->postId,
			'created_at' => Time::format($notification->createdAt),
			'read' => $notification->isRead(),
		];
	}

	private function requireRelationships(): LocalRelationshipService
	{
		if ($this->relationships === null) {
			throw new ConfigurationException('Local accounts are not configured.');
		}

		return $this->relationships;
	}
}
