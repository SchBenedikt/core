<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Notification;

use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Domain\Notification\ConversationMute;
use AlohaSocial\Core\Domain\Notification\ConversationMuteRepository;
use AlohaSocial\Core\Domain\Notification\NotificationDecision;
use AlohaSocial\Core\Domain\Notification\NotificationPolicy;
use AlohaSocial\Core\Domain\Notification\NotificationPolicyAction;
use AlohaSocial\Core\Domain\Notification\NotificationPolicyRepository;
use AlohaSocial\Core\Domain\Notification\NotificationRelationshipReader;
use AlohaSocial\Core\Domain\Notification\NotificationSender;
use AlohaSocial\Core\Domain\Notification\NotificationSenderAge;
use AlohaSocial\Core\Domain\Notification\NotificationSenderCategory;
use AlohaSocial\Core\Domain\Notification\NotificationSenderRepository;
use AlohaSocial\Core\Domain\Notification\NotificationSenderStatus;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function is_array;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Applies a recipient's notification policy and owns the sender-request
 * lifecycle.
 *
 * Policy checks are intentionally cheap local reads: a block, notification
 * mute, conversation mute or sender decision wins before the category policy
 * is consulted. The five categories come from the follow graph and an optional
 * host account-age port; no remote fetch happens while an event is recorded.
 */
final class NotificationPolicyService
{
	public const DEFAULT_REQUEST_LIMIT = 20;

	public const MAX_REQUEST_LIMIT = 40;

	public function __construct(
		private readonly NotificationPolicyRepository $policies,
		private readonly NotificationSenderRepository $senders,
		private readonly ConversationMuteRepository $conversationMutes,
		private readonly NotificationRelationshipReader $relationships,
		private readonly Clock $clock,
		private readonly ?NotificationSenderAge $ages = null,
		private readonly ?ModerationPolicy $moderation = null,
		private readonly ?NotificationPolicy $defaultPolicy = null,
	) {
	}

	public function decide(
		string $recipientId,
		string $actorId,
		?string $conversationId = null,
	): NotificationDecision {
		$sender = $this->senders->find($recipientId, $actorId);
		$category = $sender?->category ?? $this->categoryFor($recipientId, $actorId);

		if ($this->moderation !== null && !$this->moderation->allowsActor($actorId)) {
			return new NotificationDecision(NotificationPolicyAction::Drop, $category, 'moderation_blocked');
		}

		if ($conversationId !== null
			&& $this->conversationMutes->find($recipientId, $conversationId) !== null
		) {
			return new NotificationDecision(NotificationPolicyAction::Drop, $category, 'conversation_muted');
		}

		if ($sender?->status === NotificationSenderStatus::Allowed) {
			return new NotificationDecision(NotificationPolicyAction::Allow, $category, 'sender_allowed');
		}

		if ($sender?->status === NotificationSenderStatus::Dismissed) {
			return new NotificationDecision(NotificationPolicyAction::Drop, $category, 'sender_dismissed');
		}

		if ($sender?->status === NotificationSenderStatus::Held) {
			return new NotificationDecision(NotificationPolicyAction::Hold, $category, 'sender_held');
		}

		$policy = $this->policyFor($recipientId);

		return new NotificationDecision(
			$policy->actionFor($category),
			$category,
			'policy_' . $category->value,
		);
	}

	public function categoryFor(string $recipientId, string $actorId): NotificationSenderCategory
	{
		$follows = $this->relationships->follows($recipientId, $actorId);
		$followedBy = $this->relationships->isFollowedBy($recipientId, $actorId);

		if ($follows && $followedBy) {
			return NotificationSenderCategory::Mutual;
		}

		if ($follows) {
			return NotificationSenderCategory::Following;
		}

		if ($followedBy) {
			return NotificationSenderCategory::Follower;
		}

		$age = $this->ages?->ageInDays($actorId);
		$policy = $this->policyFor($recipientId);
		if ($age !== null && $age >= 0 && $age < $policy->newAccountDays) {
			return NotificationSenderCategory::New;
		}

		return NotificationSenderCategory::Stranger;
	}

	public function policyFor(string $recipientId): NotificationPolicy
	{
		return $this->policies->find(trim($recipientId)) ?? $this->defaultPolicy ?? NotificationPolicy::default();
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public function updatePolicy(string $recipientId, array $body): NotificationPolicy
	{
		$policy = $this->policyFor($recipientId);
		$errors = [];

		$actions = $body['actions'] ?? null;
		if ($actions !== null) {
			if (!is_array($actions)) {
				throw new ValidationException(['actions' => 'actions must map sender categories to allow, hold or drop.']);
			}

			foreach ($actions as $category => $action) {
				if (!is_string($category) || !is_string($action)) {
					throw new ValidationException(['actions' => 'actions must map sender categories to allow, hold or drop.']);
				}

				$categoryValue = NotificationSenderCategory::tryFrom(trim($category));
				$actionValue = NotificationPolicyAction::tryFrom(trim($action));
				if ($categoryValue === null || $actionValue === null) {
					$errors['actions'] = 'Unknown sender category or policy action.';
					continue;
				}

				$policy = $policy->withAction($categoryValue, $actionValue);
			}
		}

		$days = $body['new_account_days'] ?? null;
		if ($days !== null) {
			if (!is_int($days) || $days < 0 || $days > NotificationPolicy::MAX_NEW_ACCOUNT_DAYS) {
				$errors['new_account_days'] = 'new_account_days must be a non-negative integer.';
			} else {
				$policy = new NotificationPolicy($policy->actions, $days);
			}
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$this->policies->save($recipientId, $policy, Time::toSecondPrecision($this->clock->now()));

		return $policy;
	}

	/**
	 * @return list<NotificationSender>
	 */
	public function requests(string $recipientId, int $limit = self::DEFAULT_REQUEST_LIMIT): array
	{
		return $this->senders->forRecipient(
			$recipientId,
			NotificationSenderStatus::Held,
			max(1, min(self::MAX_REQUEST_LIMIT, $limit)),
		);
	}

	public function accept(string $recipientId, string $requestId): NotificationSender
	{
		$sender = $this->owned($recipientId, $requestId);
		if ($sender->status === NotificationSenderStatus::Allowed) {
			return $sender;
		}

		$updated = $sender->withStatus(
			NotificationSenderStatus::Allowed,
			Time::toSecondPrecision($this->clock->now()),
		);
		$this->senders->save($updated);

		return $updated;
	}

	public function dismiss(string $recipientId, string $requestId): NotificationSender
	{
		$sender = $this->owned($recipientId, $requestId);
		$updated = $sender->withStatus(
			NotificationSenderStatus::Dismissed,
			Time::toSecondPrecision($this->clock->now()),
		);
		$this->senders->save($updated);

		return $updated;
	}

	public function revoke(string $recipientId, string $requestId): bool
	{
		$sender = $this->owned($recipientId, $requestId);

		return $this->senders->remove($recipientId, $sender->actorId);
	}

	/**
	 * Records a held sender without changing an existing explicit decision.
	 */
	public function holdSender(
		string $recipientId,
		string $actorId,
		NotificationSenderCategory $category,
	): NotificationSender {
		$existing = $this->senders->find($recipientId, $actorId);
		if ($existing !== null) {
			return $existing;
		}

		$sender = new NotificationSender(
			id: NotificationSender::idFor($recipientId, $actorId),
			recipientId: $recipientId,
			actorId: $actorId,
			status: NotificationSenderStatus::Held,
			category: $category,
			createdAt: Time::toSecondPrecision($this->clock->now()),
		);
		$this->senders->save($sender);

		return $sender;
	}

	public function muteConversation(string $userId, string $conversationId): ConversationMute
	{
		$conversationId = trim($conversationId);
		if ($conversationId === '') {
			throw new ValidationException(['conversation' => 'A conversation mute needs a thread root.']);
		}

		$existing = $this->conversationMutes->find($userId, $conversationId);
		if ($existing !== null) {
			return $existing;
		}

		$mute = new ConversationMute(
			id: ConversationMute::idFor($userId, $conversationId),
			userId: $userId,
			conversationId: $conversationId,
			createdAt: Time::toSecondPrecision($this->clock->now()),
		);
		$this->conversationMutes->save($mute);

		return $mute;
	}

	public function unmuteConversation(string $userId, string $conversationId): bool
	{
		return $this->conversationMutes->remove($userId, trim($conversationId));
	}

	public function conversationMute(string $userId, string $conversationId): ?ConversationMute
	{
		return $this->conversationMutes->find($userId, trim($conversationId));
	}

	private function owned(string $recipientId, string $requestId): NotificationSender
	{
		$sender = $this->senders->findById(trim($requestId));
		if ($sender === null || $sender->recipientId !== $recipientId) {
			throw new NotificationRequestNotFoundException($requestId);
		}

		return $sender;
	}
}
