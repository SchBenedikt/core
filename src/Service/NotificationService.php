<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Records and reads local account notifications.
 *
 * The notification id is derived from the event, not generated randomly:
 * repeating a favourite or follow therefore hits the same primary key and
 * cannot create a duplicate. A notification is a historical event; removing
 * the favourite or follower does not delete it.
 *
 * Recording is best-effort: a missing notification table or a storage failure
 * is logged and does not fail the primary favourite/follow write. The event
 * source repeats the call on a retry, so the deterministic id lets a later
 * attempt heal the missing notification.
 */
final class NotificationService
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly NotificationRepository $notifications,
		private readonly Clock $clock,
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	public function recordFavourite(string $recipientId, string $actorId, string $postId): void
	{
		$this->record($recipientId, $actorId, NotificationType::Favourite, $postId);
	}

	public function recordFollow(string $recipientId, string $actorId): void
	{
		$this->record($recipientId, $actorId, NotificationType::Follow, null);
	}

	public function recordMention(string $recipientId, string $actorId, string $postId): void
	{
		$this->record($recipientId, $actorId, NotificationType::Mention, $postId);
	}

	public function recordReblog(string $recipientId, string $actorId, string $postId): void
	{
		$this->record($recipientId, $actorId, NotificationType::Reblog, $postId);
	}

	public function find(string $id): ?Notification
	{
		return $this->notifications->find($id);
	}

	/**
	 * Marks one notification read, idempotently and only for its recipient.
	 *
	 * The row stays in storage so a repeat dismiss can answer success instead
	 * of leaking whether an id ever existed. A foreign or missing id is false.
	 */
	public function markRead(string $recipientId, string $id, DateTimeImmutable $readAt): bool
	{
		// The repository owns the ownership and already-read checks. It can
		// answer success for an existing read row without an extra find, and
		// its PDO adapter maps a missing table/column to DatabaseException.
		return $this->notifications->markRead(
			$id,
			$recipientId,
			Time::toSecondPrecision($readAt),
		);
	}

	public function markAllRead(string $recipientId, DateTimeImmutable $readAt): int
	{
		return $this->notifications->markAllRead(
			$recipientId,
			Time::toSecondPrecision($readAt),
		);
	}

	/**
	 * @param list<NotificationType> $types
	 * @param list<NotificationType> $excludeTypes
	 * @param list<string>           $excludeActorIds
	 */
	public function unreadCount(
		string $recipientId,
		?string $accountId = null,
		array $types = [],
		array $excludeTypes = [],
		array $excludeActorIds = [],
	): int {
		return $this->notifications->countUnread(
			$recipientId,
			$accountId,
			$types,
			$excludeTypes,
			$excludeActorIds,
		);
	}

	/**
	 * @param list<NotificationType> $types
	 * @param list<NotificationType> $excludeTypes
	 * @param list<string>           $excludeActorIds
	 *
	 * @return list<Notification>
	 */
	public function listFor(
		string $recipientId,
		int $limit,
		?DateTimeImmutable $before = null,
		?string $beforeId = null,
		?DateTimeImmutable $after = null,
		?string $afterId = null,
		array $types = [],
		array $excludeTypes = [],
		array $excludeActorIds = [],
	): array {
		return $this->notifications->listFor(
			$recipientId,
			$limit,
			$before,
			$beforeId,
			$after,
			$afterId,
			$types,
			$excludeTypes,
			false,
			$excludeActorIds,
		);
	}

	private function record(
		string $recipientId,
		string $actorId,
		NotificationType $type,
		?string $postId,
	): void {
		if ($recipientId === $actorId) {
			// A self-favourite or self-follow is not an interaction worth a
			// notification; the services that call this may still store the edge.
			return;
		}

		$id = $this->idFor($recipientId, $actorId, $type, $postId);
		$notification = new Notification(
			id: $id,
			recipientId: $recipientId,
			actorId: $actorId,
			type: $type,
			postId: $postId,
			createdAt: Time::toSecondPrecision($this->clock->now()),
		);

		try {
			$this->notifications->insert($notification);

			return;
		} catch (DatabaseException $exception) {
			// The deterministic id is the idempotency boundary. Re-read before
			// deciding this was a real storage failure.
			try {
				if ($this->notifications->find($id) !== null) {
					return;
				}
			} catch (DatabaseException) {
				// A missing notification table is a host migration issue. Keep
				// the primary favourite/follow write intact and log the loss.
			}

			$this->logger->error('Notification could not be recorded.', [
				'recipient_id' => $recipientId,
				'actor_id' => $actorId,
				'type' => $type->value,
				'post_id' => $postId,
				'exception' => $exception,
			]);
		}
	}

	private function idFor(
		string $recipientId,
		string $actorId,
		NotificationType $type,
		?string $postId,
	): string {
		return 'notification-' . substr(
			hash('sha256', $recipientId . "\0" . $actorId . "\0" . $type->value . "\0" . ($postId ?? '')),
			0,
			32,
		);
	}
}
