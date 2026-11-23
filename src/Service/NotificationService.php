<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationEventSink;
use AlohaSocial\Core\Domain\Notification\NotificationPolicyAction;
use AlohaSocial\Core\Domain\Notification\NotificationRecordStatus;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationSender;
use AlohaSocial\Core\Domain\Notification\NotificationThreadResolver;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\Notification\NotificationPolicyService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_map;
use function max;
use function min;

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
		private readonly ?NotificationPolicyService $policy = null,
		private readonly ?NotificationEventSink $sink = null,
		private readonly ?NotificationThreadResolver $threads = null,
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	public function recordFavourite(string $recipientId, string $actorId, string $postId): void
	{
		$this->record($recipientId, $actorId, NotificationType::Favourite, $postId);
	}

	/**
	 * Records an inbound Like or EmojiReact on one of the recipient's posts.
	 *
	 * Mastodon has no reaction notification type, so it is presented as a
	 * favourite. The emoji is part of the deterministic id, which keeps two
	 * different reactions from one actor on one post as two events instead of
	 * collapsing them into one favourite notification.
	 */
	public function recordReaction(
		string $recipientId,
		string $actorId,
		string $postId,
		?string $emoji = null,
	): void {
		$this->record($recipientId, $actorId, NotificationType::Favourite, $postId, $emoji);
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

	/**
	 * @return array<string, mixed>
	 */
	public function policyFor(string $userId): array
	{
		return $this->requirePolicy()->policyFor($userId)->toArray();
	}

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	public function updatePolicy(string $userId, array $body): array
	{
		return $this->requirePolicy()->updatePolicy($userId, $body)->toArray();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function requests(string $userId, int $limit = NotificationPolicyService::DEFAULT_REQUEST_LIMIT): array
	{
		return array_map(
			static fn (NotificationSender $sender): array => $sender->toArray(),
			$this->requirePolicy()->requests($userId, max(1, min(NotificationPolicyService::MAX_REQUEST_LIMIT, $limit))),
		);
	}

	/**
	 * Accepts one sender and releases every held event from that sender.
	 *
	 * Releasing changes the stored rows only. The event sink is deliberately
	 * not called again: the user already has the event, it simply becomes
	 * visible in the existing list and unread count.
	 *
	 * @return array{request: array<string, mixed>, released: int}
	 */
	public function acceptSender(string $userId, string $requestId): array
	{
		$sender = $this->requirePolicy()->accept($userId, $requestId);
		$released = $this->notifications->markStatusForActor(
			$userId,
			$sender->actorId,
			NotificationRecordStatus::Held,
			NotificationRecordStatus::Visible,
		);

		return ['request' => $sender->toArray(), 'released' => $released];
	}

	/**
	 * @return array{request: array<string, mixed>, suppressed: int}
	 */
	public function dismissSender(string $userId, string $requestId): array
	{
		$sender = $this->requirePolicy()->dismiss($userId, $requestId);
		$suppressed = $this->notifications->markStatusForActor(
			$userId,
			$sender->actorId,
			NotificationRecordStatus::Held,
			NotificationRecordStatus::Dropped,
		);

		return ['request' => $sender->toArray(), 'suppressed' => $suppressed];
	}

	public function revokeSender(string $userId, string $requestId): bool
	{
		return $this->requirePolicy()->revoke($userId, $requestId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function muteConversation(string $userId, string $postId): array
	{
		return $this->requirePolicy()->muteConversation($userId, $this->conversationRoot($postId))->toArray();
	}

	public function unmuteConversation(string $userId, string $postId): bool
	{
		return $this->requirePolicy()->unmuteConversation($userId, $this->conversationRoot($postId));
	}

	/** @return array<string, mixed>|null */
	public function conversationMute(string $userId, string $postId): ?array
	{
		$mute = $this->requirePolicy()->conversationMute($userId, $this->conversationRoot($postId));

		return $mute?->toArray();
	}

	private function record(
		string $recipientId,
		string $actorId,
		NotificationType $type,
		?string $postId,
		?string $emoji = null,
	): void {
		if ($recipientId === $actorId) {
			// A self-favourite or self-follow is not an interaction worth a
			// notification; the services that call this may still store the edge.
			return;
		}

		$conversationId = null;
		if ($postId !== null && $this->threads !== null) {
			try {
				$conversationId = $this->threads->rootFor($postId);
			} catch (Throwable $exception) {
				// Thread resolution is an enhancement. A missing or broken thread
				// cache must not make the favourite/follow write fail.
				$this->logger->error('Notification thread root could not be resolved.', [
					'recipient_id' => $recipientId,
					'post_id' => $postId,
					'exception' => $exception,
				]);
			}
		}

		$decision = null;
		if ($this->policy !== null) {
			try {
				$decision = $this->policy->decide($recipientId, $actorId, $conversationId);
			} catch (Throwable $exception) {
				// A missing policy or sender table is a host migration issue. The
				// follow/favourite write must survive it, and an event is safer
				// shown than silently lost.
				$this->logger->error('Notification policy could not be evaluated.', [
					'recipient_id' => $recipientId,
					'actor_id' => $actorId,
					'exception' => $exception,
				]);
			}
		}

		$action = $decision?->action;
		$status = match ($action) {
			NotificationPolicyAction::Hold => NotificationRecordStatus::Held,
			NotificationPolicyAction::Drop => NotificationRecordStatus::Dropped,
			default => NotificationRecordStatus::Visible,
		};

		if ($decision !== null && $action === NotificationPolicyAction::Hold) {
			try {
				$this->policy?->holdSender($recipientId, $actorId, $decision->category);
			} catch (Throwable $exception) {
				$this->logger->error('Notification sender request could not be stored.', [
					'recipient_id' => $recipientId,
					'actor_id' => $actorId,
					'exception' => $exception,
				]);
			}
		}

		$id = $this->idFor($recipientId, $actorId, $type, $postId, $emoji);
		$notification = new Notification(
			id: $id,
			recipientId: $recipientId,
			actorId: $actorId,
			type: $type,
			postId: $postId,
			createdAt: Time::toSecondPrecision($this->clock->now()),
			status: $status,
			conversationId: $conversationId,
			policyCategory: $decision?->category,
			policyAction: $action,
		);

		try {
			$this->notifications->insert($notification);
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

			return;
		}

		if ($status->isVisible() && $this->sink !== null) {
			try {
				$this->sink->emit($notification);
			} catch (Throwable $exception) {
				// The row is committed. A host bell, digest or push failure is
				// retried by that adapter, not by the primary interaction.
				$this->logger->error('Notification event sink failed.', [
					'recipient_id' => $recipientId,
					'actor_id' => $actorId,
					'type' => $type->value,
					'exception' => $exception,
				]);
			}
		}
	}

	private function conversationRoot(string $postId): string
	{
		$postId = trim($postId);
		if ($this->threads === null) {
			return $postId;
		}

		try {
			return $this->threads->rootFor($postId) ?? $postId;
		} catch (Throwable $exception) {
			$this->logger->error('Conversation mute root could not be resolved.', [
				'post_id' => $postId,
				'exception' => $exception,
			]);

			return $postId;
		}
	}

	private function requirePolicy(): NotificationPolicyService
	{
		if ($this->policy === null) {
			throw new ConfigurationException('Notification policy is not configured.');
		}

		return $this->policy;
	}

	private function idFor(
		string $recipientId,
		string $actorId,
		NotificationType $type,
		?string $postId,
		?string $emoji = null,
	): string {
		return 'notification-' . substr(
			hash('sha256', $recipientId . "\0" . $actorId . "\0" . $type->value . "\0" . ($postId ?? '') . "\0" . ($emoji ?? '')),
			0,
			32,
		);
	}
}
